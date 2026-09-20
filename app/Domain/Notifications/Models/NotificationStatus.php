<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

/** Module 21 §27 "Delivery States" — the module's own exact 12-state list, used verbatim. */
enum NotificationStatus: string
{
    case Created = 'created';
    case Queued = 'queued';
    case Processing = 'processing';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case RetryPending = 'retry_pending';
    case Bounced = 'bounced';
    case Rejected = 'rejected';
    case Suppressed = 'suppressed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Delivered, self::Failed, self::Bounced, self::Rejected,
            self::Suppressed, self::Cancelled, self::Expired,
        ], true);
    }
}
