<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Couriers;

/** One end of a parcel: who sends it or who receives it, as printed on the label and sent to the courier. */
final class CourierParty
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $phone,
        public readonly string $address,
        public readonly ?string $city,
        public readonly ?string $province = null,
        public readonly ?string $postalCode = null,
        public readonly ?string $country = null,
    ) {}

    /** @param array<string, mixed>|null $snapshot an order address snapshot */
    public static function fromAddress(?array $snapshot, string $fallbackName, ?string $fallbackPhone): self
    {
        $snapshot ??= [];
        $line = trim(implode(', ', array_filter([(string) ($snapshot['line1'] ?? ''), (string) ($snapshot['line2'] ?? '')])));

        return new self(
            name: trim((string) ($snapshot['name'] ?? '')) ?: $fallbackName,
            phone: ($snapshot['phone'] ?? null) ?: $fallbackPhone,
            address: $line,
            city: ($snapshot['city'] ?? null) ?: null,
            province: ($snapshot['province'] ?? null) ?: null,
            postalCode: ($snapshot['postal_code'] ?? null) ?: null,
            country: ($snapshot['country'] ?? null) ?: null,
        );
    }

    /** "1 Mall Road, Lahore, Punjab 54000, PK" */
    public function oneLine(): string
    {
        return implode(', ', array_filter([$this->address, $this->city, trim(($this->province ?? '').' '.($this->postalCode ?? '')), $this->country]));
    }
}
