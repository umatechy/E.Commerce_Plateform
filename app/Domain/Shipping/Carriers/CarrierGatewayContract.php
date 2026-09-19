<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Carriers;

use App\Domain\Shipping\Models\Shipment;

/**
 * Module 13 §42-44 "Carrier Integration / Carrier Capabilities" / Final
 * Rule #11: "Carrier integrations must use provider/adapter
 * abstraction." ShipmentService depends only on this interface. Only
 * the operations B8's actual scope requires are defined (mirrors
 * PaymentGatewayContract's identical discipline from Phase B7).
 */
interface CarrierGatewayContract
{
    public function providerName(): string;

    /** Called once, immediately after a Shipment record is created. */
    public function createShipment(Shipment $shipment): CarrierShipmentResult;

    /**
     * Module 13 §53 "Carrier Webhooks". Returns false for carriers with
     * no webhook concept at all (Store Pickup, Local Delivery — both
     * are staff-updated manually, never via an external callback).
     */
    public function verifyWebhookSignature(string $rawPayload, ?string $signatureHeader, string $storeSecret): bool;

    /**
     * @return array{status: \App\Domain\Shipping\Models\ShipmentStatus, carrier_event_code: ?string, description: ?string, location: ?string, occurred_at: \Carbon\CarbonImmutable}
     */
    public function translateWebhookPayload(array $payload): array;
}
