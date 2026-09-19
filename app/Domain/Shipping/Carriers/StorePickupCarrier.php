<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Carriers;

use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\Models\ShipmentStatus;

/**
 * Module 13 §27-29 "Store Pickup". No external call, no tracking
 * number — status progresses only via staff action (Ready → the
 * customer collects it → staff marks Delivered/Collected).
 */
final class StorePickupCarrier implements CarrierGatewayContract
{
    public function providerName(): string
    {
        return 'store_pickup';
    }

    public function createShipment(Shipment $shipment): CarrierShipmentResult
    {
        return new CarrierShipmentResult(status: ShipmentStatus::Ready);
    }

    public function verifyWebhookSignature(string $rawPayload, ?string $signatureHeader, string $storeSecret): bool
    {
        return false;
    }

    public function translateWebhookPayload(array $payload): array
    {
        throw new \LogicException('Store Pickup does not support webhooks.');
    }
}
