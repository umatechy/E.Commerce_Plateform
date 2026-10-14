<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Messaging;

/**
 * Phase B50 (Module 21 §23): the order updates a store can send by SMS or
 * WhatsApp, and the platform's critical alert.
 *
 * For each: the variables in their fixed order — the order of {{1}}, {{2}} …
 * in the WhatsApp template the store submits to Meta — and the suggested
 * text. The SMS text is the platform's default (a store may replace it on
 * the Templates tab, channel SMS); WhatsApp sends the store's approved
 * template with these values.
 */
final class MessagingEvents
{
    /** @var array<string, array{label: string, variables: list<string>, text: string}> */
    public const STORE = [
        'order_confirmed' => ['label' => 'Order confirmed', 'variables' => ['customer_name', 'store_name', 'order_number', 'order_total'],
            'text' => 'Hi {{customer_name}}, thank you for your order {{order_number}} at {{store_name}}. Total: {{order_total}}.'],
        'order_shipped' => ['label' => 'Order shipped', 'variables' => ['customer_name', 'order_number', 'courier', 'tracking_number'],
            'text' => 'Hi {{customer_name}}, your order {{order_number}} is on its way with {{courier}}. Tracking number: {{tracking_number}}.'],
        'out_for_delivery' => ['label' => 'Out for delivery', 'variables' => ['customer_name', 'order_number'],
            'text' => 'Hi {{customer_name}}, your order {{order_number}} is out for delivery today.'],
        'order_delivered' => ['label' => 'Delivered', 'variables' => ['customer_name', 'order_number'],
            'text' => 'Hi {{customer_name}}, your order {{order_number}} has been delivered. Thank you for shopping with us.'],
        'delivery_failed' => ['label' => 'Delivery not possible', 'variables' => ['customer_name', 'order_number'],
            'text' => 'Hi {{customer_name}}, we could not deliver your order {{order_number}}. We will contact you to arrange it.'],
        'order_cancelled' => ['label' => 'Order cancelled', 'variables' => ['customer_name', 'order_number'],
            'text' => 'Hi {{customer_name}}, your order {{order_number}} has been cancelled.'],
        'payment_received' => ['label' => 'Payment received', 'variables' => ['customer_name', 'order_number', 'amount'],
            'text' => 'Hi {{customer_name}}, we received your payment of {{amount}} for order {{order_number}}.'],
    ];

    /** Umar Techy's own: the critical operational alert (owner decision 4: WhatsApp configurable). */
    public const PLATFORM = [
        'critical_alert' => ['label' => 'Critical alert', 'variables' => ['summary', 'reference'],
            'text' => 'Umar Techy critical alert: {{summary}} Reference {{reference}}.'],
    ];

    /** @return list<string> */
    public static function storeKeys(): array
    {
        return array_keys(self::STORE);
    }

    /** @return array{label: string, variables: list<string>, text: string}|null */
    public static function find(string $key): ?array
    {
        return self::STORE[$key] ?? self::PLATFORM[$key] ?? null;
    }

    /** The suggested WhatsApp template body for Meta: the same text with {{1}}, {{2}} … in place of the names. */
    public static function whatsappBody(string $key): string
    {
        $event = self::find($key) ?? throw new \InvalidArgumentException("Unknown messaging event {$key}");
        $body = $event['text'];
        foreach ($event['variables'] as $index => $name) {
            $body = str_replace('{{'.$name.'}}', '{{'.($index + 1).'}}', $body);
        }

        return $body;
    }

    /**
     * @param array<string, scalar> $values
     * @return list<string> the values in the template's order
     */
    public static function parameters(string $key, array $values): array
    {
        $event = self::find($key) ?? throw new \InvalidArgumentException("Unknown messaging event {$key}");

        return array_map(fn (string $name) => mb_substr(trim((string) ($values[$name] ?? '')), 0, 200) ?: '—', $event['variables']);
    }
}
