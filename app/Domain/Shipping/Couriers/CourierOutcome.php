<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Couriers;

/**
 * What a courier call established. Unknown is its own outcome: a timeout
 * after a booking request may still have booked the parcel (Module 13 §97),
 * so it is never treated as "not booked".
 */
enum CourierOutcome: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unknown = 'unknown';
}
