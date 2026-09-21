<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Exceptions\InvalidDateRangeException;
use App\Domain\Analytics\Services\DateRangeResolver;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase B12 — Date filter whitelist, custom-range validation, anti-
 * abuse range cap (Module 22 §9/§12). Pure unit tests — no database
 * required.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class DateRangeResolverTest extends TestCase
{
    public function test_today_resolves_to_the_current_day_boundaries(): void
    {
        [$start, $end] = (new DateRangeResolver())->resolve('today');

        $this->assertTrue($start->isToday());
        $this->assertTrue($end->isToday());
    }

    public function test_unknown_preset_is_rejected(): void
    {
        $this->expectException(InvalidDateRangeException::class);
        (new DateRangeResolver())->resolve('not_a_real_preset');
    }

    public function test_custom_range_requires_both_dates(): void
    {
        $this->expectException(InvalidDateRangeException::class);
        (new DateRangeResolver())->resolve('custom', '2026-01-01', null);
    }

    public function test_custom_range_rejects_start_after_end(): void
    {
        $this->expectException(InvalidDateRangeException::class);
        (new DateRangeResolver())->resolve('custom', '2026-02-01', '2026-01-01');
    }

    public function test_custom_range_rejects_exceeding_the_maximum_cap(): void
    {
        $this->expectException(InvalidDateRangeException::class);
        (new DateRangeResolver())->resolve('custom', '2020-01-01', '2026-01-01');
    }

    public function test_valid_custom_range_is_accepted(): void
    {
        [$start, $end] = (new DateRangeResolver())->resolve('custom', '2026-01-01', '2026-01-31');

        $this->assertSame('2026-01-01', $start->toDateString());
        $this->assertSame('2026-01-31', $end->toDateString());
    }

    public function test_previous_period_is_the_same_length_immediately_before(): void
    {
        $start = Carbon::parse('2026-01-08 00:00:00');
        $end = Carbon::parse('2026-01-14 23:59:59');

        [$prevStart, $prevEnd] = (new DateRangeResolver())->previousPeriod($start, $end);

        $this->assertSame('2026-01-01 00:00:00', $prevStart->toDateTimeString());
        $this->assertSame('2026-01-07 23:59:59', $prevEnd->toDateTimeString());
    }
}
