<?php

declare(strict_types=1);

namespace App\Domain\Cart\Models;

/** Module 11 §8 "Cart Lifecycle" — the module's own suggested list, used verbatim. */
enum CartStatus: string
{
    case Active = 'active';
    case CheckoutStarted = 'checkout_started';
    case Converted = 'converted';
    case Abandoned = 'abandoned';
    case Expired = 'expired';
    case Merged = 'merged';
    case Cancelled = 'cancelled';

    public function isActionable(): bool
    {
        return $this === self::Active;
    }
}
