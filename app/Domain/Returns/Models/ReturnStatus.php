<?php

declare(strict_types=1);

namespace App\Domain\Returns\Models;

/** Module 09 §46 "Return States" — the blueprint's list, used as it stands. */
enum ReturnStatus: string
{
    case Requested = 'requested';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case InTransit = 'in_transit';
    case Received = 'received';
    case Inspected = 'inspected';
    case ApprovedForRefund = 'approved_for_refund';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** Nothing more happens to a return in these states. */
    public function isClosed(): bool
    {
        return in_array($this, [self::Rejected, self::Completed, self::Cancelled], true);
    }

    /** The quantities of such a return are still "being returned": they cannot be asked for again. */
    public function holdsQuantity(): bool
    {
        return ! in_array($this, [self::Rejected, self::Cancelled], true);
    }
}
