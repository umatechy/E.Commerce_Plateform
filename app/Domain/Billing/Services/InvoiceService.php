<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Exceptions\BillingActionRefusedException;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoicePayment;
use App\Domain\Billing\Models\InvoicePaymentMethod;
use App\Domain\Billing\Models\InvoiceStatus;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Module 29 — Super Admin actions on one invoice. Each locks the
 * subscription first, then the invoice (the same order as the billing
 * run, so the two never deadlock), changes the invoice, and lets the
 * engine bring the subscription up to date in the same transaction: a
 * payment that settles an overdue invoice reactivates the store at once.
 */
final class InvoiceService
{
    public function __construct(
        private readonly InvoiceLedger $ledger,
        private readonly SubscriptionBillingEngine $engine,
    ) {}

    /**
     * @param array{amount_minor: int, method: InvoicePaymentMethod, reference?: ?string, note?: ?string, received_at: CarbonImmutable, idempotency_key: string} $data
     *
     * @throws BillingActionRefusedException
     */
    public function recordPayment(Invoice $invoice, array $data, ?User $recordedBy): InvoicePayment
    {
        return DB::transaction(function () use ($invoice, $data, $recordedBy) {
            [$subscription, $invoice] = $this->lock($invoice);

            // A retried request (same key) returns the payment it already
            // recorded instead of recording the money twice.
            $existing = InvoicePayment::query()->withoutTenantScope()->where('idempotency_key', $data['idempotency_key'])->first();

            if ($existing !== null) {
                if ($existing->invoice_id !== $invoice->id) {
                    throw new BillingActionRefusedException('idempotency_key_reused', 'This idempotency key was already used for a different invoice.');
                }

                return $existing;
            }

            $this->assertOpen($invoice);

            if ($data['amount_minor'] > $invoice->amountDue()) {
                throw new BillingActionRefusedException('amount_exceeds_balance', "The amount exceeds the {$invoice->amountDue()} still due on invoice {$invoice->number}.", 422);
            }

            $payment = InvoicePayment::query()->withoutTenantScope()->create([
                'invoice_id' => $invoice->id,
                'store_id' => $invoice->store_id,
                'amount_minor' => $data['amount_minor'],
                'currency' => $invoice->currency,
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'received_at' => $data['received_at'],
                'recorded_by' => $recordedBy?->id,
                'idempotency_key' => $data['idempotency_key'],
            ]);

            $invoice->update(['amount_paid_minor' => $invoice->amount_paid_minor + $data['amount_minor']]);

            app(AuditLogger::class)->record('billing.payment_recorded', [
                'invoice' => $invoice->number,
                'amount_minor' => $payment->amount_minor,
                'currency' => $payment->currency,
                'method' => $payment->method,
                'reference' => $payment->reference,
            ], $invoice, $invoice->store_id);

            if ($invoice->amount_paid_minor >= $invoice->total_minor) {
                $this->ledger->markPaid($invoice);
                $this->engine->process($subscription);
            }

            return $payment;
        });
    }

    /**
     * Waives the invoice: its period still counts as covered, so the
     * subscription renews and recovers as if it had been paid. Only an
     * invoice nothing was paid on can be voided — money received would
     * need a refund, which platform billing does not do yet.
     *
     * @throws BillingActionRefusedException
     */
    public function void(Invoice $invoice, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason) {
            [$subscription, $invoice] = $this->lock($invoice);
            $this->assertOpen($invoice);

            if ($invoice->amount_paid_minor > 0) {
                throw new BillingActionRefusedException('invoice_partially_paid', "Invoice {$invoice->number} has payments recorded and cannot be voided.");
            }

            $this->ledger->markVoid($invoice, $reason);
            $this->engine->process($subscription);

            return $invoice;
        });
    }

    /**
     * Gives the store more time: the dunning ladder counts from the new
     * due date, and a store that is no longer overdue recovers at once.
     *
     * @throws BillingActionRefusedException
     */
    public function extendDueDate(Invoice $invoice, CarbonImmutable $dueAt, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $dueAt, $reason) {
            [$subscription, $invoice] = $this->lock($invoice);
            $this->assertOpen($invoice);

            if ($dueAt->lessThanOrEqualTo($invoice->due_at)) {
                throw new BillingActionRefusedException('due_date_not_later', 'The new due date must be later than the current one.', 422);
            }

            $previous = $invoice->due_at->toIso8601String();
            $invoice->update(['due_at' => $dueAt]);

            app(AuditLogger::class)->record('billing.invoice_due_date_extended', [
                'invoice' => $invoice->number, 'from' => $previous, 'to' => $dueAt, 'reason' => $reason,
            ], $invoice, $invoice->store_id);

            $this->engine->process($subscription);

            return $invoice;
        });
    }

    /** @return array{0: Subscription, 1: Invoice} */
    private function lock(Invoice $invoice): array
    {
        $subscription = Subscription::query()->withoutTenantScope()->lockForUpdate()->findOrFail($invoice->subscription_id);
        $invoice = Invoice::query()->withoutTenantScope()->lockForUpdate()->findOrFail($invoice->id);

        return [$subscription, $invoice];
    }

    /** @throws BillingActionRefusedException */
    private function assertOpen(Invoice $invoice): void
    {
        if ($invoice->status !== InvoiceStatus::Open) {
            throw new BillingActionRefusedException('invoice_not_open', "Invoice {$invoice->number} is {$invoice->status->value}.");
        }
    }
}
