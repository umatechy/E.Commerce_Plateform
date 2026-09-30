<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use Carbon\CarbonImmutable;

enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Yearly => 12,
        };
    }

    /**
     * The end of the period that starts at $start, counted from the
     * billing anchor (anchor + n intervals) rather than from $start, so
     * a Jan 31 anchor bills Feb 28/29, then Mar 31 — never drifting to
     * the 28th for good.
     */
    public function periodEnd(CarbonImmutable $start, CarbonImmutable $anchor): CarbonImmutable
    {
        $n = 1;

        while (($end = $anchor->addMonthsNoOverflow($n * $this->months()))->lessThanOrEqualTo($start)) {
            $n++;
        }

        return $end;
    }
}
