<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

/** Outcome of one PaymentTransaction attempt (distinct from the Payment aggregate's own PaymentStatus). */
enum TransactionStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Pending = 'pending';
}
