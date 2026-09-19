<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

/** Module 08 §21 "Reservation Lifecycle" — the module's own suggested list. */
enum ReservationStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Released = 'released';
    case Converted = 'converted';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
