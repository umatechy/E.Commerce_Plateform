<?php

declare(strict_types=1);

namespace App\Domain\Events\Models;

enum OutboxEventStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Published = 'published';
    case Failed = 'failed';
}
