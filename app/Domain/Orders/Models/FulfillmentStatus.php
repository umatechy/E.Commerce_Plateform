<?php

declare(strict_types=1);

namespace App\Domain\Orders\Models;

/** Module 09 §17 — separate from OrderStatus/PaymentStatus. Module 13 owns execution. */
enum FulfillmentStatus: string
{
    case Unfulfilled = 'unfulfilled';
    case PartiallyFulfilled = 'partially_fulfilled';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';
}
