<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

/** Module 21 §7 "Recipient Resolution" — only the 2 recipient identity types B11's actual event sources need. */
enum RecipientType: string
{
    case Customer = 'customer';
    case User = 'user'; // staff/store owner
}
