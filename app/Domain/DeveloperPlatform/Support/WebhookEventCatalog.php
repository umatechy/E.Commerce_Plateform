<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Support;

/**
 * Phase B53 (Module 31 §34–35 "Webhook Platform", "Event Catalog and Event
 * Versioning"; SRS API-013) — the events a store's developer applications
 * may subscribe to, and what each one carries.
 *
 * Until B53 a subscription could name any internal event type (backups,
 * settings, billing, security …) and receive its internal payload. Now
 * only these commerce events are offered and delivered; the payload is the
 * one documented here (ids are the same numeric ids the developer API
 * returns). Adding an event is a documented, additive change; changing a
 * payload needs a new event version.
 */
final class WebhookEventCatalog
{
    public const VERSION = '2026-10-14';

    /** event => [description, payload fields => [type, description]] */
    public const EVENTS = [
        'order.created' => ['A new order was placed.', [
            'order_id' => ['integer', 'The order (GET /orders/{id}).'], 'order_number' => ['string', 'The order number customers see.'],
            'grand_total_minor' => ['integer', 'The total in minor units (paisa for PKR).'],
        ]],
        'order.cancelled' => ['An order was cancelled.', ['order_id' => ['integer', 'The order.'], 'reason' => ['string', 'Why, as a code.']]],
        'payment.succeeded' => ['An online payment was confirmed.', [
            'payment_id' => ['integer', 'The payment.'], 'order_id' => ['integer', 'Its order.'], 'provider' => ['string', 'jazzcash, easypaisa …'], 'amount_minor' => ['integer', 'The amount in minor units.'],
        ]],
        'payment.refunded' => ['A payment was refunded, fully or in part.', ['payment_id' => ['integer', 'The payment.'], 'amount_minor' => ['integer', 'The refunded amount.'], 'full' => ['boolean', 'Whether the whole payment was refunded.']]],
        'shipment.created' => ['A shipment was created for an order.', ['shipment_id' => ['integer', 'The shipment.'], 'order_id' => ['integer', 'Its order.'], 'carrier' => ['string', 'The courier code.']]],
        'shipment.status_changed' => ['A shipment moved on (picked up, in transit, delivered …).', ['shipment_id' => ['integer', 'The shipment.'], 'status' => ['string', 'The new status.'], 'direction' => ['string', 'outbound or return.']]],
        'shipment.cancelled' => ['A shipment was cancelled before pickup.', ['shipment_id' => ['integer', 'The shipment.'], 'order_id' => ['integer', 'Its order.']]],
        'return.requested' => ['A customer asked to return items.', ['return_id' => ['integer', 'The return.'], 'order_id' => ['integer', 'Its order.']]],
        'return.approved' => ['The store approved a return.', ['return_id' => ['integer', 'The return.'], 'order_id' => ['integer', 'Its order.']]],
        'return.received' => ['Returned items arrived at the store.', ['return_id' => ['integer', 'The return.'], 'order_id' => ['integer', 'Its order.']]],
        'return.refunded' => ['A return was refunded.', ['return_id' => ['integer', 'The return.'], 'order_id' => ['integer', 'Its order.'], 'amount_minor' => ['integer', 'Refunded to the payment.'], 'store_credit_minor' => ['integer', 'Given as store credit.']]],
    ];

    public static function has(string $event): bool
    {
        return isset(self::EVENTS[$event]);
    }

    /**
     * Only the documented fields of a payload leave the platform.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function payload(string $event, array $payload): array
    {
        return array_intersect_key($payload, self::EVENTS[$event][1] ?? []);
    }
}
