<?php

declare(strict_types=1);

namespace App\Domain\Packages\Services;

use App\Domain\Packages\Models\UsagePeriod;
use App\Domain\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Module 04 §11–12 "Usage Tracking / Usage Counter Strategy" and the
 * milestone's dedicated "Concurrency" section.
 *
 * Uses a single atomic SQL statement (INSERT ... ON DUPLICATE KEY
 * UPDATE) against the usage_counters table's unique
 * (store_id, metric_key, period_start) constraint — this is what makes
 * two simultaneous increments for the same store+metric+period safe
 * without any application-level locking or a read-then-write race
 * window (the exact race this milestone's prompt describes and
 * requires closing).
 */
final class UsageTrackingService
{
    public function __construct(private readonly TenantContext $context) {}

    public function increment(string $metricKey, UsagePeriod $period, int $by = 1): void
    {
        $this->applyDelta($metricKey, $period, $by);
    }

    /**
     * Used when the underlying operation that incremented usage is
     * later reversed (e.g. an order is cancelled) — this milestone:
     * "operation fails BUT usage permanently increments" must never
     * happen; callers are responsible for calling decrement() in the
     * same domain transaction as the reversing state change.
     */
    public function decrement(string $metricKey, UsagePeriod $period, int $by = 1): void
    {
        $this->applyDelta($metricKey, $period, -$by);
    }

    public function currentUsage(string $metricKey, UsagePeriod $period): int
    {
        [$periodStart, $periodEnd] = $period->currentBoundary(CarbonImmutable::now());

        return (int) \App\Domain\Packages\Models\UsageCounter::query()
            ->where('metric_key', $metricKey)
            ->where('period_start', $periodStart)
            ->value('count') ?? 0;
    }

    private function applyDelta(string $metricKey, UsagePeriod $period, int $delta): void
    {
        $storeId = $this->context->storeId();
        [$periodStart, $periodEnd] = $period->currentBoundary(CarbonImmutable::now());
        $now = CarbonImmutable::now();

        // GREATEST(...,0) prevents a decrement from ever driving the
        // counter negative (which would itself be a form of drift).
        DB::statement(
            'INSERT INTO usage_counters (store_id, metric_key, period_start, period_end, count, created_at, updated_at) '.
            'VALUES (?, ?, ?, ?, GREATEST(?, 0), ?, ?) '.
            'ON DUPLICATE KEY UPDATE count = GREATEST(count + ?, 0), updated_at = ?',
            [$storeId, $metricKey, $periodStart, $periodEnd, $delta, $now, $now, $delta, $now]
        );
    }

    /**
     * Reconciliation hook (Module 04 §12: "Critical usage values should
     * have a reconciliation mechanism to detect counter drift").
     * Deliberately NOT implemented for any concrete metric in B2 — there
     * is no source-of-truth table (products, orders, ...) yet to
     * recount against. Each owning module (Phase B3+ for max_products,
     * Phase B5+ for max_monthly_orders, etc.) MUST implement a concrete
     * recalculation callback and register it here; this method
     * documents the extension point rather than silently omitting it.
     *
     * @param callable(): int $recount returns the true current count
     *                                  from the source-of-truth table
     */
    public function reconcile(string $metricKey, UsagePeriod $period, callable $recount): void
    {
        $storeId = $this->context->storeId();
        [$periodStart, $periodEnd] = $period->currentBoundary(CarbonImmutable::now());

        \App\Domain\Packages\Models\UsageCounter::query()->updateOrCreate(
            ['store_id' => $storeId, 'metric_key' => $metricKey, 'period_start' => $periodStart],
            ['period_end' => $periodEnd, 'count' => max(0, $recount())]
        );
    }
}
