<?php

declare(strict_types=1);

namespace App\Domain\Orders\Models;

/**
 * Module 09 §18 — deliberately a SEPARATE enum from OrderStatus (§16:
 * "these must remain separate"). Detailed payment architecture belongs
 * to Module 12 — B5 only stores and displays this status; no gateway
 * code sets it beyond the initial Unpaid default.
 */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Paid = 'paid';
    case PartiallyPaid = 'partially_paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case RefundPending = 'refund_pending';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
}
