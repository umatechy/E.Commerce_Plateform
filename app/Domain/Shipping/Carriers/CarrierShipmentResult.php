<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Carriers;

use App\Domain\Shipping\Models\ShipmentStatus;

final class CarrierShipmentResult
{
    public function __construct(
        public readonly ShipmentStatus $status,
        public readonly ?string $trackingNumber = null,
        public readonly ?string $labelUrl = null,
    ) {}
}
