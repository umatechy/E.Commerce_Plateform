<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

/** Module 12 §9 "Payment Transaction" — the module's own list, used verbatim. */
enum TransactionType: string
{
    case Authorization = 'authorization';
    case Capture = 'capture';
    case Sale = 'sale';
    case Void = 'void';
    case Refund = 'refund';
    case PartialRefund = 'partial_refund';
    case Reversal = 'reversal';
    case Adjustment = 'adjustment';
}
