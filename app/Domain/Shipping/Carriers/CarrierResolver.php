<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Carriers;

/** The ONE place a carrier name maps to a concrete adapter (mirrors Phase B7's GatewayResolver). */
final class CarrierResolver
{
    public function resolve(string $provider): CarrierGatewayContract
    {
        return match ($provider) {
            'store_pickup' => new StorePickupCarrier(),
            'local_delivery' => new LocalDeliveryCarrier(),
            'mock_courier' => new MockCourierCarrier(),
            default => throw new \InvalidArgumentException("Unknown shipping carrier: {$provider}"),
        };
    }
}
