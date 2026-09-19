<?php

declare(strict_types=1);

namespace App\Domain\Orders\Models;

/**
 * Module 09 §15 "Order State Machine" — the module's own suggested
 * list, used verbatim. Only a subset of transitions are actually wired
 * by B5's OrderStateMachine (see that class) — return/refund states
 * exist as schema-ready enum cases for Modules that own those
 * workflows (see docs/development/b5-inspection-findings.md).
 */
enum OrderStatus: string
{
    case Draft = 'draft';
    case PendingConfirmation = 'pending_confirmation';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case ReadyToFulfill = 'ready_to_fulfill';
    case Fulfilling = 'fulfilling';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case ReturnRequested = 'return_requested';
    case PartiallyReturned = 'partially_returned';
    case Returned = 'returned';
    case RefundPending = 'refund_pending';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Failed = 'failed';

    /** Module 09 Final Rule #11: "Confirmed orders must not be casually mutated." */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::Completed, self::Refunded, self::Failed], true);
    }
}
