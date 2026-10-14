<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Couriers;

/** A tracking read: the events (oldest first), or why there are none this time. */
final class TrackingResult
{
    /** @param list<TrackingUpdate> $updates */
    public function __construct(
        public readonly CourierOutcome $outcome,
        public readonly array $updates = [],
        public readonly ?string $message = null,
    ) {}
}
