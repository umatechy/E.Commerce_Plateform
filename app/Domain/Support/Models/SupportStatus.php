<?php

declare(strict_types=1);

namespace App\Domain\Support\Models;

/**
 * open              waiting for the answering team
 * awaiting_customer the team replied and waits for the requester
 * on_hold           parked by the team (e.g. waiting on a courier)
 * resolved          answered; the requester may still reply for a while
 * closed            final
 */
enum SupportStatus: string
{
    case Open = 'open';
    case AwaitingCustomer = 'awaiting_customer';
    case OnHold = 'on_hold';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /** Still being worked on (counts for SLA and the inbox). */
    public function isActive(): bool
    {
        return in_array($this, [self::Open, self::AwaitingCustomer, self::OnHold], true);
    }
}
