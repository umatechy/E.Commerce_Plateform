<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Inventory\Models\ReservationStatus;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Orders\Models\CancellationReason;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\PaymentStatus as OrderPaymentStatus;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Payments\Exceptions\PaymentAlreadyExistsException;
use App\Domain\Payments\Exceptions\RefundExceedsRefundableBalanceException;
use App\Domain\Payments\Gateways\GatewayResolver;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\PaymentStatus;
use App\Domain\Payments\Models\PaymentTransaction;
use App\Domain\Payments\Models\PaymentWebhookEvent;
use App\Domain\Payments\Models\TransactionStatus;
use App\Domain\Payments\Models\TransactionType;
use App\Domain\Payments\Models\WebhookEventStatus;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ONLY code path that creates/mutates a Payment or
 * PaymentTransaction (Module 12 Non-Negotiable Rules #5-6). Mirrors
 * OrderService's "dumb model, service-only writes" pattern exactly.
 *
 * SENSITIVE DATA DISCIPLINE (Module 12 §33/§58): `metadata` columns on
 * Payment/PaymentTransaction/PaymentWebhookEvent NEVER receive a raw
 * gateway secret, API key, or full card/account number — every write
 * path in this class passes only the specific, already-non-sensitive
 * fields a gateway adapter's translateWebhookPayload()/initiate()
 * result explicitly returns, never the entire raw request/response
 * payload.
 */
final class PaymentService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly GatewayResolver $gateways,
        private readonly PaymentStateMachine $stateMachine,
        private readonly OrderService $orders,
        private readonly RecordsOutboxEvents $outbox,
    ) {}

    /**
     * Module 12 §7/§14/§24. Amount is ALWAYS derived from the
     * authoritative Order — never from any caller-supplied value
     * (Step 8, Non-Negotiable Rule #2).
     *
     * @throws PaymentAlreadyExistsException
     */
    public function createForOrder(Order $order, PaymentMethod $method, string $idempotencyKey): Payment
    {
        if ($existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        if (Payment::query()->where('order_id', $order->id)->exists()) {
            throw new PaymentAlreadyExistsException();
        }

        return DB::transaction(function () use ($order, $method, $idempotencyKey) {
            $payment = Payment::query()->create([
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'method' => $method,
                'status' => PaymentStatus::Created,
                'amount_minor' => $order->grand_total_minor, // server-authoritative — see class docblock
                'currency' => $order->currency,
                'idempotency_key' => $idempotencyKey,
            ]);

            // Module 12 §14 "Zero-Value Orders" — no gateway is ever
            // called for a zero-payable order.
            if ($order->grand_total_minor <= 0) {
                $this->transitionTo($payment, PaymentStatus::Paid);
                $this->recordTransaction($payment, TransactionType::Sale, TransactionStatus::Succeeded, 0, null, null, null, actorId: null, idempotencyKey: "{$idempotencyKey}:zero-value");

                return $payment->fresh();
            }

            $gateway = $this->gateways->resolve($method);
            $result = $gateway->initiate($payment);

            $payment->update([
                'provider_payment_reference' => $result->providerPaymentReference,
                'metadata' => $result->metadata,
            ]);

            $this->transitionTo($payment, $result->status);

            $this->outbox->recordEvent(
                eventType: 'payment.initiated',
                payload: ['payment_id' => $payment->id, 'order_id' => $order->id, 'method' => $method->value],
                idempotencyKey: "payment:{$payment->id}:initiated",
            );

            return $payment->fresh();
        });
    }

    public function redirectUrlFor(Payment $payment): ?string
    {
        if ($payment->status !== PaymentStatus::RequiresAction) {
            return null;
        }

        // Recomputed on demand (the mock gateway derives it
        // deterministically from the payment's own public_id) rather
        // than stored — a real provider's redirect URL may be
        // time-limited and worth re-issuing rather than caching.
        return $this->gateways->resolve($payment->method)->initiate($payment)->redirectUrl;
    }

    /**
     * Module 12 §63-64 "Manual Payment Confirmation / Cash Collection".
     * Staff-only (enforced by the caller's Policy check, not here) —
     * this method assumes authorization already passed and focuses
     * purely on the domain transition + audit trail.
     */
    public function recordManualConfirmation(Payment $payment, int $amountMinor, string $reference, ?string $notes, int $actorId): PaymentTransaction
    {
        return DB::transaction(function () use ($payment, $amountMinor, $reference, $notes, $actorId) {
            $transaction = $this->recordTransaction(
                $payment, TransactionType::Sale, TransactionStatus::Succeeded, $amountMinor,
                $reference, null, null, actorId: $actorId,
                idempotencyKey: "payment:{$payment->id}:manual:".Str::ulid(),
                // Staff notes are part of the audit trail (Module 12 §63);
                // they were accepted by the request but never stored.
                metadata: $notes !== null ? ['notes' => $notes] : null,
            );

            $this->transitionTo($payment, PaymentStatus::Paid);

            return $transaction;
        });
    }

    /**
     * Module 12 §26-30 "Webhooks". `$rawPayload`/`$signatureHeader` are
     * verified INSIDE this method via the resolved gateway — the
     * caller (webhook controller) never decides trust on its own
     * (Non-Negotiable Rule #12).
     */
    public function handleWebhook(string $provider, string $rawPayload, ?string $signatureHeader, string $externalEventId): PaymentWebhookEvent
    {
        // Module 12 §28 "Webhook Idempotency" — dedup BEFORE any
        // signature check or domain processing, so a genuinely
        // replayed delivery is a guaranteed no-op regardless of
        // whatever else changed.
        if ($existing = PaymentWebhookEvent::query()->where('provider', $provider)->where('external_event_id', $externalEventId)->first()) {
            return $existing;
        }

        $payload = json_decode($rawPayload, true) ?? [];
        $providerReference = $payload['provider_payment_reference'] ?? null;

        $payment = $providerReference !== null
            ? Payment::query()->withoutTenantScope()->where('provider_payment_reference', $providerReference)->first()
            : null;

        $event = PaymentWebhookEvent::query()->create([
            'store_id' => $payment?->store_id,
            'payment_id' => $payment?->id,
            'provider' => $provider,
            'external_event_id' => $externalEventId,
            'status' => WebhookEventStatus::Received,
            'payload' => $payload,
        ]);

        if ($payment === null) {
            // Step 10: an unresolvable reference is never trusted —
            // this is logged as an orphaned event for investigation,
            // never treated as authorization for anything.
            $event->update(['status' => WebhookEventStatus::Ignored, 'failure_reason' => 'No matching payment for provider_payment_reference.', 'processed_at' => now()]);

            return $event;
        }

        $gateway = $this->gateways->resolveByProviderName($provider);
        $storeSecret = \App\Domain\Tenancy\Models\Store::query()->find($payment->store_id)->payment_webhook_secret ?? '';

        if (! $gateway->verifyWebhookSignature($rawPayload, $signatureHeader, $storeSecret)) {
            $event->update(['status' => WebhookEventStatus::Failed, 'failure_reason' => 'Signature verification failed.', 'processed_at' => now()]);

            return $event;
        }

        $this->context->resolveToStore($payment->store_id);

        DB::transaction(function () use ($gateway, $payload, $payment, $event) {
            $translated = $gateway->translateWebhookPayload($payload);

            $this->recordTransaction(
                $payment, $translated['type'], $translated['status'], $translated['amount_minor'],
                $translated['provider_transaction_reference'], $translated['failure_code'], $translated['failure_reason'],
                actorId: null, idempotencyKey: "webhook:{$event->id}",
            );

            if ($translated['status'] === TransactionStatus::Succeeded) {
                $this->transitionTo($payment, PaymentStatus::Paid);
            } else {
                $this->transitionTo($payment, PaymentStatus::Failed);
                $this->releaseReservationAndCancelOrder($payment, CancellationReason::PaymentFailed);
            }

            $event->update(['status' => WebhookEventStatus::Processed, 'processed_at' => now()]);
        });

        return $event->fresh();
    }

    /**
     * Module 12 §46-49 "Payment Refunds / Refundable Balance".
     *
     * @throws RefundExceedsRefundableBalanceException
     */
    public function refund(Payment $payment, int $amountMinor, ?string $reason, int $actorId, string $idempotencyKey): PaymentTransaction
    {
        if ($existing = PaymentTransaction::query()->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        return DB::transaction(function () use ($payment, $amountMinor, $reason, $actorId, $idempotencyKey) {
            // The refundable balance is read under a row lock: checked
            // outside the transaction, two concurrent partial refunds could
            // both pass the check and together exceed the captured amount.
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $refundable = $payment->refundableAmountMinor();

            if ($amountMinor > $refundable) {
                throw new RefundExceedsRefundableBalanceException($amountMinor, $refundable);
            }

            $isFullRefund = $amountMinor === $refundable;

            $transaction = $this->recordTransaction(
                $payment,
                $isFullRefund ? TransactionType::Refund : TransactionType::PartialRefund,
                TransactionStatus::Succeeded, $amountMinor, null, null, $reason,
                actorId: $actorId, idempotencyKey: $idempotencyKey,
            );

            $newStatus = $isFullRefund ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded;

            // A second partial refund leaves the status unchanged; asking the
            // state machine for partially_refunded -> partially_refunded
            // made every second partial refund fail.
            if ($payment->status !== $newStatus) {
                $this->transitionTo($payment, $newStatus);
            }

            $this->outbox->recordEvent(
                eventType: 'payment.refunded',
                payload: ['payment_id' => $payment->id, 'amount_minor' => $amountMinor, 'full' => $isFullRefund],
                idempotencyKey: "payment:{$payment->id}:refunded:{$idempotencyKey}",
            );

            return $transaction;
        });
    }

    private function transitionTo(Payment $payment, PaymentStatus $to): void
    {
        $this->stateMachine->assertCanTransition($payment->status, $to);

        $updates = ['status' => $to];

        if ($to->isTerminalSuccess()) {
            $updates['completed_at'] = now();
        } elseif ($to->isTerminalFailure()) {
            $updates['failed_at'] = now();
        }

        $payment->update($updates);

        $this->syncOrderPaymentStatus($payment->fresh());
    }

    /**
     * Module 12 Final Rule #7: Payment status and Order status are
     * separate concerns. This mapping is the ONE deliberate,
     * documented approximation from Module 12's 14-state model down to
     * Order's simpler summary enum (Phase B5) — see
     * docs/architecture/b7-payment.md "Order Payment-Status Mapping".
     */
    private function syncOrderPaymentStatus(Payment $payment): void
    {
        $orderStatus = match ($payment->status) {
            PaymentStatus::Created, PaymentStatus::Pending, PaymentStatus::RequiresAction => OrderPaymentStatus::Pending,
            PaymentStatus::Authorized => OrderPaymentStatus::Authorized,
            PaymentStatus::Paid => OrderPaymentStatus::Paid,
            PaymentStatus::PartiallyPaid => OrderPaymentStatus::PartiallyPaid,
            PaymentStatus::Failed, PaymentStatus::Disputed => OrderPaymentStatus::Failed,
            PaymentStatus::Cancelled, PaymentStatus::Expired => OrderPaymentStatus::Cancelled,
            PaymentStatus::RefundPending => OrderPaymentStatus::RefundPending,
            PaymentStatus::PartiallyRefunded => OrderPaymentStatus::PartiallyRefunded,
            PaymentStatus::Refunded, PaymentStatus::Reversed => OrderPaymentStatus::Refunded,
        };

        $this->orders->syncPaymentStatus($payment->order, $orderStatus);
    }

    /**
     * Module 12 Step 15 "Inventory Interaction" — reuses B5's
     * OrderService::cancelOrder() and, through it, B4's
     * InventoryService::release() UNCHANGED. No new reservation logic.
     */
    private function releaseReservationAndCancelOrder(Payment $payment, CancellationReason $reason): void
    {
        $order = $payment->order;

        if (! $order->status->isTerminal() && app(\App\Domain\Orders\Services\OrderStateMachine::class)->isCancellable($order)) {
            $this->orders->cancelOrder($order, $reason, 'Payment failed or expired.', actorId: null);
        }
    }

    private function recordTransaction(
        Payment $payment,
        TransactionType $type,
        TransactionStatus $status,
        int $amountMinor,
        ?string $providerTransactionReference,
        ?string $failureCode,
        ?string $failureReason,
        ?int $actorId,
        string $idempotencyKey,
        ?array $metadata = null,
    ): PaymentTransaction {
        return PaymentTransaction::query()->create([
            'payment_id' => $payment->id,
            'type' => $type,
            'status' => $status,
            'amount_minor' => $amountMinor,
            'currency' => $payment->currency,
            'provider_transaction_reference' => $providerTransactionReference,
            'failure_code' => $failureCode,
            'failure_reason' => $failureReason,
            'actor_id' => $actorId,
            'idempotency_key' => $idempotencyKey,
            'metadata' => $metadata,
        ]);
    }
}
