<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Models;

/** Module 13 §15 "Shipping Method" — the module's own list; only these 6 have real calculation/fulfillment logic in B8 (see docs/development/b8-inspection-findings.md). */
enum ShippingMethodType: string
{
    case FlatRate = 'flat_rate';
    case Free = 'free';
    case WeightBased = 'weight_based';
    case PriceBased = 'price_based';
    case StorePickup = 'store_pickup';
    case LocalDelivery = 'local_delivery';

    public function requiresCarrier(): bool
    {
        return in_array($this, [self::FlatRate, self::WeightBased, self::PriceBased], true);
    }
}
