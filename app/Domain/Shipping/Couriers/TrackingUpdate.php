<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Couriers;

use App\Domain\Shipping\Models\ShipmentStatus;
use Carbon\CarbonImmutable;

/**
 * One courier event in platform terms (Module 13 §51–52). `key` identifies
 * it at the courier, so the same event is recorded once however often it is
 * read; adapters build it from the courier's event id or code and time.
 */
final class TrackingUpdate
{
    public function __construct(
        public readonly ShipmentStatus $status,
        public readonly string $key,
        public readonly CarbonImmutable $occurredAt,
        public readonly ?string $code = null,
        public readonly ?string $description = null,
        public readonly ?string $location = null,
    ) {}
}
