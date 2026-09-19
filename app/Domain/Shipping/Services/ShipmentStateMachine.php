<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Services;

use App\Domain\Shipping\Exceptions\InvalidShipmentStateTransitionException;
use App\Domain\Shipping\Models\ShipmentStatus;

/**
 * Module 13 §49, this milestone's Step 12. Mirrors
 * OrderStateMachine/PaymentStateMachine's exact pattern — the ONLY
 * place a Shipment's status transition validity is decided.
 */
final class ShipmentStateMachine
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'draft' => ['ready', 'label_created', 'cancelled'],
        'ready' => ['label_created', 'picked_up', 'cancelled'], // label_created only for carrier-based methods; store_pickup/local_delivery skip straight to picked_up-equivalent staff actions
        'label_created' => ['pickup_requested', 'picked_up', 'cancelled'], // 'picked_up' direct: a real courier's webhook often reports pickup without a separate prior "pickup requested" local step ever having been recorded
        'pickup_requested' => ['picked_up', 'cancelled'],
        'picked_up' => ['in_transit', 'delivered', 'delivery_failed'], // store_pickup: "picked_up" IS the customer collecting it, so delivered is reachable directly
        'in_transit' => ['out_for_delivery', 'delivery_failed', 'lost'],
        'out_for_delivery' => ['delivered', 'delivery_failed'],
        'delivery_failed' => ['out_for_delivery', 'returning', 'cancelled'],
        'returning' => ['returned'],
        // Delivered/Returned/Cancelled/Lost/Damaged are terminal — see
        // ShipmentStatus::isTerminal(); no outgoing transitions
        // registered, which IS the enforcement mechanism.
    ];

    /**
     * @throws InvalidShipmentStateTransitionException
     */
    public function assertCanTransition(ShipmentStatus $from, ShipmentStatus $to): void
    {
        $allowed = self::TRANSITIONS[$from->value] ?? [];

        if (! in_array($to->value, $allowed, true)) {
            throw new InvalidShipmentStateTransitionException($from, $to);
        }
    }
}
