<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Couriers;

/**
 * Module 13 §43 "Carrier Capabilities": what a courier integration can do,
 * as built and as known. The engine and the screens never call or offer an
 * operation that is not declared. `verified`: checked against the courier's
 * sandbox (none of the real couriers is, yet — owner decision 2).
 */
final class CourierCapabilities
{
    public function __construct(
        public readonly bool $booking,
        public readonly bool $label,
        public readonly bool $cancel,
        public readonly bool $trackingPoll,
        public readonly bool $notifications,
        public readonly bool $signedNotifications,
        public readonly bool $cod,
        public readonly bool $returnPickup,
        public readonly bool $verified,
        public readonly ?string $note = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'booking' => $this->booking, 'label' => $this->label, 'cancel' => $this->cancel,
            'tracking_poll' => $this->trackingPoll, 'notifications' => $this->notifications,
            'signed_notifications' => $this->signedNotifications, 'cod' => $this->cod,
            'return_pickup' => $this->returnPickup, 'verified' => $this->verified, 'note' => $this->note,
        ];
    }
}
