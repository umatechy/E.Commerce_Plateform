<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Models;

/** Whether shoppers can see a store right now, and if not, why. */
enum StorefrontAvailability: string
{
    case Open = 'open';
    /** The owner has not launched the store yet (status pending_setup). */
    case NotLaunched = 'not_launched';
    /** Suspended store, or a subscription that grants no access. */
    case Unavailable = 'unavailable';
    /** Phase B51: the store has closed (closed, archived or deleted). */
    case Closed = 'closed';
    /** Platform-wide maintenance mode (platform.maintenance_mode). */
    case Maintenance = 'maintenance';

    public function message(): string
    {
        return match ($this) {
            self::Open => 'Open.',
            self::NotLaunched => 'This store is getting ready and will open soon.',
            self::Unavailable => 'This store is temporarily unavailable.',
            self::Closed => 'This shop has closed and no longer takes orders.',
            self::Maintenance => 'We are performing scheduled maintenance. Please check back shortly.',
        };
    }
}
