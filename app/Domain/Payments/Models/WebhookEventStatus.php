<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

enum WebhookEventStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Failed = 'failed';
    case Ignored = 'ignored'; // e.g. a duplicate/replayed event, safely no-op'd
}
