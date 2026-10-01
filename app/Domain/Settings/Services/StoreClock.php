<?php

declare(strict_types=1);

namespace App\Domain\Settings\Services;

use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Module 33 §50 "Time Zone Management" — the ONE place the current
 * store's timezone is turned into dates (SRS DATA-010, LOC-004).
 *
 * The rules this class keeps:
 * - Timestamps are stored in UTC (§50.2). Nothing here changes storage.
 * - A date or time a person typed without an offset ("2026-10-01",
 *   "2026-10-01 09:00") means that wall-clock time in the STORE's
 *   timezone. A value that carries its own offset or "Z" keeps it.
 * - "Today", "this month" and every other calendar period start and end
 *   at the store's local midnight, not the server's.
 * - Everything returned for a query or for storage is already converted
 *   to UTC, because Eloquent formats a Carbon binding in whatever
 *   timezone the instance carries.
 *
 * Outside a store context (platform routes, Super Admin, console) the
 * timezone is UTC: platform billing and usage periods are UTC periods by
 * definition (§50.5).
 */
final class StoreClock
{
    public function __construct(
        private readonly ConfigService $config,
        private readonly TenantContext $context,
    ) {}

    public function timezone(): \DateTimeZone
    {
        return new \DateTimeZone($this->timezoneName());
    }

    public function timezoneName(): string
    {
        if (! $this->context->hasStore()) {
            return 'UTC';
        }

        $name = (string) $this->config->get('store.timezone');

        // The validator only stores IANA identifiers; a value that is no
        // longer one (a removed zone) must not take every date down.
        return in_array($name, \DateTimeZone::listIdentifiers(), true) ? $name : 'UTC';
    }

    /** The current moment, carrying the store's timezone (for calendar arithmetic). */
    public function now(): Carbon
    {
        return Carbon::now($this->timezone());
    }

    /**
     * A person's date/time input as a UTC instant. Input without an
     * offset is wall-clock time in the store's timezone.
     *
     * @throws \Carbon\Exceptions\InvalidFormatException
     */
    public function parse(string $input): Carbon
    {
        return $this->parseLocal($input)->utc();
    }

    /**
     * The same input, still in the timezone it was read in. Use it for
     * startOfDay()/endOfDay() and then call ->utc().
     *
     * @throws \Carbon\Exceptions\InvalidFormatException
     */
    public function parseLocal(string $input): Carbon
    {
        // PHP applies the timezone argument only when the string names no
        // zone of its own, so the instant is right either way.
        return Carbon::parse($input, $this->timezone())->setTimezone($this->timezone());
    }

    /**
     * Splits a UTC range into pieces in which the store's UTC offset is
     * constant, so SQL can group by the store's local date with a plain
     * fixed shift (MySQL's CONVERT_TZ needs timezone tables that are not
     * installed everywhere). A range without a daylight-saving change is
     * one piece.
     *
     * @return list<array{start: Carbon, end: Carbon, offset_seconds: int}>
     */
    public function constantOffsetSegments(Carbon $start, Carbon $end): array
    {
        $zone = $this->timezone();
        $start = $start->copy()->utc();
        $end = $end->copy()->utc();

        $segments = [];
        $cursor = $start->copy();

        // getTransitions() lists the offset in force at $start first, then every change up to $end.
        $changes = array_slice($zone->getTransitions($start->getTimestamp(), $end->getTimestamp()) ?: [], 1);

        foreach ($changes as $change) {
            $changeAt = Carbon::createFromTimestampUTC($change['ts']);
            if ($changeAt->lessThanOrEqualTo($cursor) || $changeAt->greaterThan($end)) {
                continue;
            }

            $segments[] = ['start' => $cursor, 'end' => $changeAt->copy()->subSecond(), 'offset_seconds' => $zone->getOffset($cursor)];
            $cursor = $changeAt;
        }

        $segments[] = ['start' => $cursor, 'end' => $end, 'offset_seconds' => $zone->getOffset($cursor)];

        return $segments;
    }
}
