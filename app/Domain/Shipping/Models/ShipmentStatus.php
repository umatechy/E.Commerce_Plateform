<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Models;

/** Module 13 §49 "Shipment States" — the module's own suggested list, used verbatim. */
enum ShipmentStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case LabelCreated = 'label_created';
    case PickupRequested = 'pickup_requested';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case DeliveryFailed = 'delivery_failed';
    case Returning = 'returning';
    case Returned = 'returned';
    case Cancelled = 'cancelled';
    case Lost = 'lost';
    case Damaged = 'damaged';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled, self::Returned, self::Lost], true);
    }
}
