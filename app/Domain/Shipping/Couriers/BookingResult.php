<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Couriers;

/**
 * A courier's answer to a booking. `labelPdf`: the courier's own label when
 * it returns one (stored privately); otherwise the platform prints one.
 * `message` is for staff; it never carries credentials.
 */
final class BookingResult
{
    public function __construct(
        public readonly CourierOutcome $outcome,
        public readonly ?string $trackingNumber = null,
        public readonly ?string $carrierReference = null,
        public readonly ?string $labelPdf = null,
        public readonly ?string $message = null,
    ) {}
}
