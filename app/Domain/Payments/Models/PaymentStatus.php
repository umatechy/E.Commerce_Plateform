<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

/**
 * Module 12 §7 "Payment Status" — the module's own suggested list,
 * used verbatim. DELIBERATELY separate from
 * App\Domain\Orders\Models\PaymentStatus (Order's own summary field,
 * Phase B5) — Module 12 Final Rule #7: "Payment status and Order
 * status are separate concerns." PaymentService is the only code that
 * translates a change here into an update of the Order's summary
 * field (via OrderService::syncPaymentStatus(), Phase B7 addition).
 */
enum PaymentStatus: string
{
    case Created = 'created';
    case Pending = 'pending';
    case RequiresAction = 'requires_action';
    case Authorized = 'authorized';
    case Paid = 'paid';
    case PartiallyPaid = 'partially_paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case RefundPending = 'refund_pending';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Disputed = 'disputed';
    case Reversed = 'reversed';

    public function isTerminalSuccess(): bool
    {
        return in_array($this, [self::Paid, self::Refunded], true);
    }

    public function isTerminalFailure(): bool
    {
        return in_array($this, [self::Failed, self::Cancelled, self::Expired], true);
    }
}
