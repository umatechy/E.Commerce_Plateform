<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Couriers;

/**
 * One parcel as a courier needs it (Module 13 §47, §70): our reference
 * (never the courier's id), both ends, size, and the cash to collect —
 * decided by the server from the order's payment, never typed by staff.
 */
final class CourierBooking
{
    public function __construct(
        public readonly string $reference,
        public readonly string $direction,
        public readonly CourierParty $sender,
        public readonly CourierParty $receiver,
        public readonly int $pieces,
        public readonly int $weightGrams,
        public readonly int $codAmountMinor,
        public readonly string $currency,
        public readonly string $description,
        public readonly ?string $orderNumber = null,
    ) {}

    public function isReturn(): bool
    {
        return $this->direction === 'return';
    }
}
