<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Carriers;

use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\Models\ShipmentStatus;

/**
 * Module 13 §30 "Local Delivery" — the store operates its own
 * delivery (e.g. a rider). No external carrier API; status is
 * staff-updated manually via ShipmentController, never a webhook.
 */
final class LocalDeliveryCarrier implements CarrierGatewayContract
{
    public function providerName(): string
    {
        return 'local_delivery';
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
        throw new \LogicException('Local Delivery does not support webhooks.');
    }
}
