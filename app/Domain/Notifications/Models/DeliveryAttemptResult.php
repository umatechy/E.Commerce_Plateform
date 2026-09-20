<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

enum DeliveryAttemptResult: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
