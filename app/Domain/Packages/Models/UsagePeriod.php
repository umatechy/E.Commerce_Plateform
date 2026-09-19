<?php

declare(strict_types=1);

namespace App\Domain\Packages\Models;

use Carbon\CarbonImmutable;

/**
 * Module 04 §13 "Usage Periods" — the module's own exact list.
 */
enum UsagePeriod: string
{
    case Persistent = 'persistent'; // never resets (e.g. product count)
    case Monthly = 'monthly';
    case Daily = 'daily';
    case OneTime = 'one_time';
    case Concurrent = 'concurrent'; // evaluated live, not counted over a window

    /**
     * The current period's [start, end) boundary for counter bucketing.
     * Persistent/OneTime/Concurrent periods use a fixed epoch bucket
     * (period never changes) so UsageCounter has a stable, single row
     * per store+metric for the lifetime of the store.
     */
    public function currentBoundary(CarbonImmutable $now): array
    {
        return match ($this) {
            self::Monthly => [$now->startOfMonth(), $now->startOfMonth()->addMonth()],
            self::Daily => [$now->startOfDay(), $now->startOfDay()->addDay()],
            self::Persistent, self::OneTime, self::Concurrent => [
                CarbonImmutable::createFromTimestamp(0),
                CarbonImmutable::createFromTimestamp(0)->addYears(100),
            ],
        };
    }
}
