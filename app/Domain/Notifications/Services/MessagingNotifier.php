<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Messaging\MessagingEvents;
use App\Domain\Notifications\Messaging\PhoneNumber;
use App\Domain\Notifications\Models\MessagingAccount;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationMessageType;
use App\Domain\Notifications\Models\NotificationTemplate;
use App\Domain\Notifications\Models\RecipientType;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Models\Payment;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Settings\Services\Currencies;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\Services\ShippingLabelService;
use App\Domain\Tenancy\Models\Store;

/**
 * Phase B50 (gap G10; Module 21 §9, §23): order updates to customers by SMS
 * and WhatsApp, beside the emails of Phase B11. Called by
 * NotificationEventRouter for the events the business modules already emit
 * (no event is invented here).
 *
 * Sent only when: the store switched that update on for that channel, the
 * store has a working account for the channel, and the order has a phone
 * number that can be read. WhatsApp also needs the customer's yes — on the
 * order (checkout) or in their account — as Meta's policy and Module 21 §9
 * require. Every message is transactional, never marketing, and is created
 * once per order, update and channel (idempotency key).
 */
final class MessagingNotifier
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly ConfigService $config,
    ) {}

    /** @param array<string, mixed> $payload */
    public function route(string $eventType, array $payload): void
    {
        match ($eventType) {
            'order.created' => $this->forOrder('order_confirmed', (int) ($payload['order_id'] ?? 0), []),
            'order.cancelled' => $this->forOrder('order_cancelled', (int) ($payload['order_id'] ?? 0), []),
            'payment.succeeded' => $this->forPayment((int) ($payload['payment_id'] ?? 0)),
            'shipment.status_changed' => $this->forShipment((int) ($payload['shipment_id'] ?? 0), (string) ($payload['status'] ?? ''), (string) ($payload['direction'] ?? 'outbound')),
            default => null,
        };
    }

    private function forPayment(int $paymentId): void
    {
        $payment = Payment::query()->find($paymentId);
        if ($payment !== null) {
            $this->forOrder('payment_received', $payment->order_id, ['amount' => Currencies::readable($payment->amount_minor, $payment->currency)], "payment:{$payment->id}");
        }
    }

    private function forShipment(int $shipmentId, string $status, string $direction): void
    {
        $event = match ($status) {
            'picked_up' => 'order_shipped',
            'out_for_delivery' => 'out_for_delivery',
            'delivered' => 'order_delivered',
            'delivery_failed' => 'delivery_failed',
            default => null,
        };
        $shipment = $event !== null && $direction === 'outbound' ? Shipment::query()->find($shipmentId) : null;
        if ($shipment === null) {
            return;
        }
        $this->forOrder((string) $event, $shipment->order_id, [
            'courier' => app(ShippingLabelService::class)->carrierName($shipment),
            'tracking_number' => (string) ($shipment->tracking_number ?? '—'),
        ], "shipment:{$shipment->id}");
    }

    /** @param array<string, string> $extra */
    private function forOrder(string $event, int $orderId, array $extra, ?string $scope = null): void
    {
        $order = Order::query()->with('customer')->find($orderId);
        if ($order === null) {
            return;
        }
        $phone = PhoneNumber::e164($order->guest_phone ?? $order->customer->phone ?? ($order->shipping_address_snapshot['phone'] ?? null));
        if ($phone === null) {
            return;
        }
        $values = [
            'customer_name' => (string) ($order->customer->name ?? $order->guest_name ?? 'there'),
            'store_name' => (string) (Store::query()->find($order->store_id)->name ?? ''),
            'order_number' => $order->order_number,
            'order_total' => Currencies::readable($order->grand_total_minor, $order->currency),
            ...$extra,
        ];

        foreach ([NotificationChannel::Sms, NotificationChannel::WhatsApp] as $channel) {
            if (! in_array($event, (array) $this->config->get("messaging.{$channel->value}_events"), true)) {
                continue;
            }
            if (! MessagingAccount::query()->where('store_id', $order->store_id)->where('channel', $channel->value)->where('is_enabled', true)->exists()) {
                continue;
            }
            if ($channel === NotificationChannel::WhatsApp && ! $order->whatsapp_opt_in && ! ($order->customer->whatsapp_opt_in ?? false)) {
                continue; // no yes to WhatsApp from this customer
            }
            $this->notifications->send(
                NotificationMessageType::Transactional, $channel, RecipientType::Customer, $order->customer_id, $phone,
                '', $this->text($event, $channel), $values,
                "messaging:{$event}:".($scope ?? "order:{$order->id}").":{$channel->value}",
                $event, providerParameters: MessagingEvents::parameters($event, $values),
            );
        }
    }

    /** The store's own SMS text when it published one on the Templates tab, else the platform's. */
    private function text(string $event, NotificationChannel $channel): string
    {
        $own = NotificationTemplate::query()->where('key', "messaging.{$event}")->where('channel', $channel)->where('is_published', true)->first();

        return $own->body ?? (string) MessagingEvents::find($event)['text'];
    }
}
