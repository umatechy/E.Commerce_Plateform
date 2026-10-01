<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Exceptions\InvalidDateRangeException;
use App\Domain\Settings\Services\StoreClock;
use Illuminate\Support\Carbon;

/**
 * Module 22 §12 "Date & Time Filters" — the module's own exact list,
 * used verbatim. Every filter key is checked against a fixed
 * whitelist; a `custom` range validates start <= end and enforces a
 * MAXIMUM_CUSTOM_RANGE_DAYS anti-abuse cap (this milestone's own Step
 * 9: "prevent extremely expensive unrestricted queries" — a documented
 * guard, not an invented package/commercial limit).
 *
 * TIMEZONE (Module 33 §50, Phase B28): every period starts and ends at
 * midnight in the store's timezone (StoreClock). The boundaries are
 * returned in UTC, ready for a query against the UTC timestamps.
 */
final class DateRangeResolver
{
    private const MAXIMUM_CUSTOM_RANGE_DAYS = 366;

    private const ALLOWED_PRESETS = [
        'today', 'yesterday', 'last_7_days', 'last_30_days',
        'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'this_year',
    ];

    public function __construct(private readonly StoreClock $clock) {}

    /**
     * @return array{0: Carbon, 1: Carbon} [start, end] — both inclusive boundaries, in UTC
     * @throws InvalidDateRangeException
     */
    public function resolve(string $preset, ?string $customStart = null, ?string $customEnd = null): array
    {
        if ($preset === 'custom') {
            return $this->resolveCustom($customStart, $customEnd);
        }

        if (! in_array($preset, self::ALLOWED_PRESETS, true)) {
            throw new InvalidDateRangeException("Unsupported date filter: {$preset}");
        }

        $now = $this->clock->now();

        [$start, $end] = match ($preset) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'last_7_days' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            'last_30_days' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            'this_week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'last_week' => [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek()],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'this_quarter' => [$now->copy()->firstOfQuarter(), $now->copy()->lastOfQuarter()],
            'this_year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
        };

        return [$start->utc(), $end->utc()];
    }

    /**
     * Module 22 §13 "Comparative Periods" — Architectural Decision: ONLY
     * "previous period of equal length immediately preceding" is
     * implemented (see inspection findings).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function previousPeriod(Carbon $start, Carbon $end): array
    {
        // Carbon 3 diffs are signed floats: $end->diffInSeconds($start)
        // is NEGATIVE, which pushed the "previous" period into the future.
        $lengthInSeconds = (int) $start->diffInSeconds($end, true) + 1;

        return [$start->copy()->subSeconds($lengthInSeconds), $start->copy()->subSecond()];
    }

    /**
     * @throws InvalidDateRangeException
     */
    private function resolveCustom(?string $customStart, ?string $customEnd): array
    {
        if ($customStart === null || $customEnd === null) {
            throw new InvalidDateRangeException('A custom date range requires both start and end dates.');
        }

        try {
            $start = $this->clock->parseLocal($customStart)->startOfDay();
            $end = $this->clock->parseLocal($customEnd)->endOfDay();
        } catch (\Throwable) {
            throw new InvalidDateRangeException('Invalid date format.');
        }

        if ($start->greaterThan($end)) {
            throw new InvalidDateRangeException('The start date must be before the end date.');
        }

        if ((int) $start->diffInDays($end, true) > self::MAXIMUM_CUSTOM_RANGE_DAYS) {
            throw new InvalidDateRangeException('The custom date range cannot exceed '.self::MAXIMUM_CUSTOM_RANGE_DAYS.' days.');
        }

        return [$start->utc(), $end->utc()];
    }
}
