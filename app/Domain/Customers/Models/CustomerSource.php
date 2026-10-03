<?php

declare(strict_types=1);

namespace App\Domain\Customers\Models;

/** Module 10 §7: how a customer record came to exist. Older records have none. */
enum CustomerSource: string
{
    case Registered = 'registered';
    case Staff = 'staff';
    case Import = 'import';
}
