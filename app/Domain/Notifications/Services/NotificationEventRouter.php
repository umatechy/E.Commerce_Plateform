<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Marketing\Models\Campaign;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationMessageType;
use App\Domain\Notifications\Models\NotificationTemplate;
use App\Domain\Notifications\Models\RecipientType;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Models\Payment;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Cart\Models\Cart;

/**
 * The first real consumer of this platform's outbox events (see
 * docs/development/b11-inspection-findings.md "Critical Finding") —
 * called from ConsumeOutboxEventJob::handle() BEFORE the row is marked
 * Published. Maps each known event_type to a notification send,
 * reusing the EXACT event names/payloads already emitted by
 * OrderService (B5), PaymentService (B7), ShipmentService (B8), and
 * Marketing (B10) — no event names were invented or renamed.
 *
 * An unrecognized event_type is silently ignored — not every outbox
 * event needs a notification (Non-Negotiable: do not invent a
 * notification for something the module never specified).
 */
final class NotificationEventRouter
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function route(string $eventType, array $payload): void
    {
        match ($eventType) {
            'order.created' => $this->handleOrderCreated($payload),
            'order.cancelled' => $this->handleOrderCancelled($payload),
            'payment.initiated' => $this->handlePaymentInitiated($payload),
            'payment.refunded' => $this->handlePaymentRefunded($payload),
            'shipment.created' => $this->handleShipmentCreated($payload),
            'marketing.recipient_queued' => $this->handleMarketingRecipientQueued($payload),
            'marketing.abandoned_cart_detected' => $this->handleAbandonedCartDetected($payload),
            'billing.invoice_issued', 'billing.invoice_paid', 'billing.payment_overdue' => $this->handleBillingEvent($eventType, $payload),
            // Module 34 (Phase B26): support emails.
            'support.ticket_created', 'support.agent_replied', 'support.requester_replied', 'support.sla_breached' => app(\App\Domain\Support\Services\SupportNotifier::class)->route($eventType, $payload),
            // Phase B30 (gap G4): critical operational alerts to the platform's operators.
            default => in_array($eventType, \App\Domain\Monitoring\Services\CriticalAlertNotifier::EVENTS, true)
                ? app(\App\Domain\Monitoring\Services\CriticalAlertNotifier::class)->route($eventType, $payload)
                : null,
        };
    }

    private function handleOrderCreated(array $payload): void
    {
        $order = Order::query()->find($payload['order_id']);
        if ($order === null) {
            return;
        }

        $destination = $order->customer->email ?? $order->guest_email;
        if ($destination === null) {
            return;
        }

        [$subject, $body] = $this->resolveTemplate('order.created', NotificationChannel::Email, [
            'Your order {{order.number}} is confirmed',
            'Hi {{customer.name}}, thank you for your order {{order.number}}. Your total is {{order.total}} {{order.currency}}.',
        ]);

        $this->notifications->send(
            NotificationMessageType::Transactional, NotificationChannel::Email,
            RecipientType::Customer, $order->customer_id, $destination,
            $subject, $body,
            [
                'order.number' => $order->order_number,
                'order.total' => number_format($order->grand_total_minor / 100, 2),
                'order.currency' => $order->currency,
                'customer.name' => $order->customer->name ?? $order->guest_name ?? 'there',
            ],
            "notification:order:{$order->id}:created",
            'order.created',
        );
    }

    private function handleOrderCancelled(array $payload): void
    {
        $order = Order::query()->find($payload['order_id']);
        if ($order === null) {
            return;
        }

        $destination = $order->customer->email ?? $order->guest_email;
        if ($destination === null) {
            return;
        }

        [$subject, $body] = $this->resolveTemplate('order.cancelled', NotificationChannel::Email, [
            'Your order {{order.number}} has been cancelled',
            'Hi {{customer.name}}, your order {{order.number}} has been cancelled. Reason: {{order.cancellation_reason}}.',
        ]);

        $this->notifications->send(
            NotificationMessageType::Transactional, NotificationChannel::Email,
            RecipientType::Customer, $order->customer_id, $destination,
            $subject, $body,
            [
                'order.number' => $order->order_number,
                'order.cancellation_reason' => $payload['reason'] ?? 'not specified',
                'customer.name' => $order->customer->name ?? $order->guest_name ?? 'there',
            ],
            "notification:order:{$order->id}:cancelled",
            'order.cancelled',
        );
    }

    private function handlePaymentInitiated(array $payload): void
    {
        $payment = Payment::query()->find($payload['payment_id']);
        $order = $payment?->order;
        if ($payment === null || $order === null) {
            return;
        }

        $destination = $order->customer->email ?? $order->guest_email;
        if ($destination === null) {
            return;
        }

        [$subject, $body] = $this->resolveTemplate('payment.initiated', NotificationChannel::Email, [
            'Payment received for order {{order.number}}',
            'Hi {{customer.name}}, we have started processing your {{payment.method}} payment for order {{order.number}}.',
        ]);

        $this->notifications->send(
            NotificationMessageType::Transactional, NotificationChannel::Email,
            RecipientType::Customer, $order->customer_id, $destination,
            $subject, $body,
            [
                'order.number' => $order->order_number,
                'payment.method' => $payment->method->value,
                'customer.name' => $order->customer->name ?? $order->guest_name ?? 'there',
            ],
            "notification:payment:{$payment->id}:initiated",
            'payment.initiated',
        );
    }

    private function handlePaymentRefunded(array $payload): void
    {
        $payment = Payment::query()->find($payload['payment_id']);
        $order = $payment?->order;
        if ($payment === null || $order === null) {
            return;
        }

        $destination = $order->customer->email ?? $order->guest_email;
        if ($destination === null) {
            return;
        }

        [$subject, $body] = $this->resolveTemplate('payment.refunded', NotificationChannel::Email, [
            'Refund processed for order {{order.number}}',
            'Hi {{customer.name}}, a refund of {{payment.refund_amount}} {{order.currency}} has been processed for order {{order.number}}.',
        ]);

        $this->notifications->send(
            NotificationMessageType::Transactional, NotificationChannel::Email,
            RecipientType::Customer, $order->customer_id, $destination,
            $subject, $body,
            [
                'order.number' => $order->order_number,
                'order.currency' => $order->currency,
                'payment.refund_amount' => number_format(((int) $payload['amount_minor']) / 100, 2),
                'customer.name' => $order->customer->name ?? $order->guest_name ?? 'there',
            ],
            "notification:payment:{$payment->id}:refunded:".($payload['amount_minor'] ?? '0'),
            'payment.refunded',
        );
    }

    private function handleShipmentCreated(array $payload): void
    {
        $shipment = Shipment::query()->find($payload['shipment_id']);
        $order = $shipment?->order;
        if ($shipment === null || $order === null) {
            return;
        }

        $destination = $order->customer->email ?? $order->guest_email;
        if ($destination === null) {
            return;
        }

        [$subject, $body] = $this->resolveTemplate('shipment.created', NotificationChannel::Email, [
            'Your order {{order.number}} has shipped',
            'Hi {{customer.name}}, your order {{order.number}} is on its way via {{shipment.carrier}}.{{shipment.tracking_line}}',
        ]);

        $this->notifications->send(
            NotificationMessageType::Transactional, NotificationChannel::Email,
            RecipientType::Customer, $order->customer_id, $destination,
            $subject, $body,
            [
                'order.number' => $order->order_number,
                'shipment.carrier' => $shipment->carrier,
                // Fully resolved here — NotificationTemplateRenderer
                // does a single, non-recursive substitution pass (a
                // deliberate security property, see
                // NotificationTemplateRenderer's docblock), so a
                // variable's OWN value must never itself contain
                // {{...}} template syntax expecting a second pass.
                'shipment.tracking_line' => $shipment->tracking_number !== null
                    ? " Tracking number: {$shipment->tracking_number}"
                    : '',
                'customer.name' => $order->customer->name ?? $order->guest_name ?? 'there',
            ],
            "notification:shipment:{$shipment->id}:created",
            'shipment.created',
        );
    }

    private function handleMarketingRecipientQueued(array $payload): void
    {
        $campaign = Campaign::query()->find($payload['campaign_id']);
        $customer = \App\Domain\Orders\Models\Customer::query()->find($payload['customer_id']);
        if ($campaign === null || $customer === null) {
            return;
        }

        $this->notifications->send(
            NotificationMessageType::Marketing, NotificationChannel::Email,
            RecipientType::Customer, $customer->id, $customer->email,
            $campaign->subject, $campaign->body.'{{unsubscribe.line}}',
            [
                'customer.name' => $customer->name,
                'unsubscribe.line' => $this->unsubscribeLine($customer),
            ],
            "notification:campaign:{$campaign->id}:customer:{$customer->id}",
            'marketing.recipient_queued',
        );
    }

    private function handleAbandonedCartDetected(array $payload): void
    {
        $cart = Cart::query()->withoutTenantScope()->find($payload['cart_id']);
        $customer = \App\Domain\Orders\Models\Customer::query()->find($payload['customer_id']);
        if ($cart === null || $customer === null) {
            return;
        }

        [$subject, $body] = $this->resolveTemplate('marketing.abandoned_cart_detected', NotificationChannel::Email, [
            'You left something in your cart',
            'Hi {{customer.name}}, you still have items waiting in your cart. Come back and complete your order!{{unsubscribe.line}}',
        ]);

        $this->notifications->send(
            NotificationMessageType::Marketing, NotificationChannel::Email,
            RecipientType::Customer, $customer->id, $customer->email,
            $subject, $body,
            ['customer.name' => $customer->name, 'unsubscribe.line' => $this->unsubscribeLine($customer)],
            "notification:cart:{$cart->id}:abandoned",
            'marketing.abandoned_cart_detected',
        );
    }

    /**
     * Module 29 (Phase B23) — platform billing mail to the store's Owner.
     * These are the platform's own messages to a merchant, so a store's
     * customer-facing templates never replace them (no resolveTemplate()).
     * A zero-total invoice needs no mail.
     */
    private function handleBillingEvent(string $eventType, array $payload): void
    {
        $invoice = \App\Domain\Billing\Models\Invoice::query()->withoutTenantScope()->find($payload['invoice_id'] ?? 0);
        $owner = $invoice !== null ? app(\App\Domain\Billing\Services\BillingContact::class)->ownerOf($invoice->store_id) : null;

        if ($invoice === null || $owner === null || $invoice->total_minor === 0) {
            return;
        }

        $stage = (string) ($payload['stage'] ?? '');
        [$subject, $body] = match ($eventType) {
            'billing.invoice_issued' => [
                'Invoice {{invoice.number}} for {{store.name}}',
                'Hi {{owner.name}}, invoice {{invoice.number}} for {{invoice.total}} {{invoice.currency}} covers {{invoice.period}}. It is due on {{invoice.due_date}}.',
            ],
            'billing.invoice_paid' => [
                'Payment received for invoice {{invoice.number}}',
                'Hi {{owner.name}}, thank you. Invoice {{invoice.number}} ({{invoice.total}} {{invoice.currency}}) is paid in full.',
            ],
            default => match ($stage) {
                'grace_period' => [
                    'Action needed: invoice {{invoice.number}} is overdue',
                    'Hi {{owner.name}}, invoice {{invoice.number}} ({{invoice.total}} {{invoice.currency}}) is still unpaid. Your store will be suspended on {{subscription.grace_ends}} unless it is paid.',
                ],
                'suspended' => [
                    '{{store.name}} has been suspended',
                    'Hi {{owner.name}}, {{store.name}} has been suspended because invoice {{invoice.number}} is unpaid. Paying it restores the store immediately.',
                ],
                'expired' => [
                    'Your {{store.name}} subscription has expired',
                    'Hi {{owner.name}}, the subscription for {{store.name}} has expired because invoice {{invoice.number}} was not paid. Your data is kept; contact us to reactivate.',
                ],
                default => [
                    'Payment overdue for invoice {{invoice.number}}',
                    'Hi {{owner.name}}, invoice {{invoice.number}} ({{invoice.total}} {{invoice.currency}}) was due on {{invoice.due_date}} and is now overdue.',
                ],
            },
        };

        $subscription = $invoice->subscription()->withoutTenantScope()->first();

        $this->notifications->send(
            NotificationMessageType::Transactional, NotificationChannel::Email,
            RecipientType::User, $owner->id, $owner->email,
            $subject, $body,
            [
                'owner.name' => $owner->name,
                'store.name' => (string) ($invoice->bill_to['store'] ?? ''),
                'invoice.number' => $invoice->number,
                'invoice.total' => number_format($invoice->total_minor / 100, 2),
                'invoice.currency' => $invoice->currency,
                'invoice.period' => $invoice->period_start->toDateString().' to '.$invoice->period_end->toDateString(),
                'invoice.due_date' => $invoice->due_at->toDateString(),
                'subscription.grace_ends' => $subscription?->grace_period_ends_at?->toDateString() ?? $invoice->due_at->toDateString(),
            ],
            "notification:invoice:{$invoice->id}:".($eventType === 'billing.payment_overdue'
                ? "overdue:{$stage}:".($payload['due_at'] ?? '')
                : substr($eventType, strlen('billing.invoice_'))),
            $eventType,
        );
    }

    /**
     * Module 21 §15/§85-86 "Unsubscribe / Link Security" — a real,
     * signed, no-login-required unsubscribe URL appended to every
     * marketing email. The signature (UnsubscribeController::signatureFor())
     * is store-secret-keyed HMAC — this method never invents its own
     * verification scheme, it calls the same one the controller checks.
     */
    private function unsubscribeLine(\App\Domain\Orders\Models\Customer $customer): string
    {
        $store = \App\Domain\Tenancy\Models\Store::query()->find($customer->store_id);
        if ($store === null) {
            return '';
        }

        $signature = \App\Domain\Notifications\Http\Controllers\UnsubscribeController::signatureFor($store, NotificationChannel::Email, $customer->email);
        $url = url('/api/v1/public/notifications/unsubscribe').'?'.http_build_query([
            'store' => $store->slug, 'channel' => 'email', 'destination' => $customer->email, 'signature' => $signature,
        ]);

        return " Unsubscribe: {$url}";
    }

    /**
     * Module 21 §12 "Fallback Behavior" — a published NotificationTemplate
     * for this (store, key, channel) is used if one exists; otherwise a
     * sensible, embedded default is used so every event always
     * produces a real notification even before a store configures its
     * own templates.
     *
     * @return array{0: string, 1: string} [subject, body]
     */
    private function resolveTemplate(string $key, NotificationChannel $channel, array $default): array
    {
        $template = NotificationTemplate::query()
            ->where('key', $key)->where('channel', $channel)->where('is_published', true)
            ->first();

        return $template !== null ? [$template->subject ?? $default[0], $template->body] : $default;
    }
}
