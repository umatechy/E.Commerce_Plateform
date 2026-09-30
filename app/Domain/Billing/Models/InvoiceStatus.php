<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

enum InvoiceStatus: string
{
    case Open = 'open';
    case Paid = 'paid';
    /** Waived: issued in error or written off by a Super Admin; the period still counts as covered. */
    case Void = 'void';
    /** The subscription expired unpaid; kept for the record, no longer payable. */
    case Uncollectible = 'uncollectible';

    /** The period this invoice bills for may start: it was paid or waived. */
    public function isSettled(): bool
    {
        return $this === self::Paid || $this === self::Void;
    }
}
