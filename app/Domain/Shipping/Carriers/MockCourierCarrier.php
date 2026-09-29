<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Carriers;

use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\Models\ShipmentStatus;
use Carbon\CarbonImmutable;

/**
 * A TEST-MODE-ONLY stand-in for a real courier's create-shipment +
 * webhook-tracking shape (Module 13 §42-43). No real HTTP call to any
 * external carrier is ever made — mirrors Phase B7's
 * MockRedirectGateway precedent exactly, including its HMAC-SHA256
 * signature scheme keyed by the STORE's own `shipment_webhook_secret`.
 */
final class MockCourierCarrier implements CarrierGatewayContract
{
    public function providerName(): string
    {
        return 'mock_courier';
    }

    public function createShipment(Shipment $shipment): CarrierShipmentResult
    {
        // A real adapter would call the carrier's "create shipment" API
        // here. This mock deterministically derives a tracking number
        // from the shipment's own public_id so tests can construct
        // valid follow-up webhook payloads without any network call.
        $trackingNumber = 'MOCK-'.strtoupper(substr($shipment->public_id, 0, 12));

        return new CarrierShipmentResult(status: ShipmentStatus::LabelCreated, trackingNumber: $trackingNumber);
    }

    public function verifyWebhookSignature(string $rawPayload, ?string $signatureHeader, string $storeSecret): bool
    {
        if ($signatureHeader === null || $storeSecret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawPayload, $storeSecret);

        return hash_equals($expected, $signatureHeader);
    }

    public function translateWebhookPayload(array $payload): array
    {
        $status = match ($payload['event'] ?? null) {
            'picked_up' => ShipmentStatus::PickedUp,
            'in_transit' => ShipmentStatus::InTransit,
            'out_for_delivery' => ShipmentStatus::OutForDelivery,
            'delivered' => ShipmentStatus::Delivered,
            'delivery_failed' => ShipmentStatus::DeliveryFailed,
            default => throw new \InvalidArgumentException('Unrecognized mock courier webhook event: '.json_encode($payload['event'] ?? null)),
        };

        return [
            'status' => $status,
            'carrier_event_code' => $payload['event'],
            'description' => $payload['description'] ?? null,
            'location' => $payload['location'] ?? null,
            'occurred_at' => isset($payload['occurred_at']) ? CarbonImmutable::parse($payload['occurred_at']) : CarbonImmutable::now(),
        ];
    }
}
