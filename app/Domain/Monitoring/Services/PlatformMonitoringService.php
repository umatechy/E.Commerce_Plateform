<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Services;

use App\Domain\DeveloperPlatform\Models\ApiRequestLog;
use App\Domain\Events\Models\OutboxEvent;
use App\Domain\Events\Models\OutboxEventStatus;
use App\Domain\Monitoring\Models\HealthStatus;
use App\Domain\Monitoring\Models\StoreHealthSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Module 24 platform-wide monitoring for Super Admin (Phase B21). Every
 * query is an explicit cross-tenant read (withoutTenantScope), reachable
 * only through the super_admin.platform route group.
 */
final class PlatformMonitoringService
{
    /**
     * The newest snapshot of every store, most severe first — the
     * "which stores need attention" view. Reads the hourly snapshots
     * instead of recomputing every store per request.
     */
    /** @return LengthAwarePaginator<int, StoreHealthSnapshot> */
    public function storeHealthOverview(?HealthStatus $status, int $perPage = 50): LengthAwarePaginator
    {
        $latestPerStore = StoreHealthSnapshot::query()->withoutTenantScope()
            ->selectRaw('MAX(id)')
            ->groupBy('store_id');

        return StoreHealthSnapshot::query()->withoutTenantScope()
            ->with(['store' => fn ($q) => $q->select(['id', 'public_id', 'name', 'slug'])])
            ->whereIn('id', $latestPerStore)
            ->when($status !== null, fn ($q) => $q->where('overall_status', $status->value))
            ->orderByRaw("CASE overall_status WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * ADR-004 §17: "a growing pending/failed outbox count is a first-class
     * operational signal". Reported alongside Laravel's own failed_jobs
     * table, the job-queue view of the same failures (ADR-004 "dead-letter").
     *
     * @return array{status: string, by_status: array<string, int>, oldest_pending_minutes: ?int, failed_recently: int, failed_jobs: int}
     */
    public function outboxBacklog(): array
    {
        $now = CarbonImmutable::now();
        $window = (int) config('monitoring.store_health.failure_window_hours');

        $byStatus = OutboxEvent::query()->withoutTenantScope()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->toBase()
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total)
            ->all();

        $oldestPending = OutboxEvent::query()->withoutTenantScope()->where('status', OutboxEventStatus::Pending->value)->min('created_at');
        $oldestPendingMinutes = $oldestPending !== null ? (int) floor(CarbonImmutable::parse($oldestPending)->diffInMinutes($now, true)) : null;

        $failedRecently = OutboxEvent::query()->withoutTenantScope()
            ->where('status', OutboxEventStatus::Failed->value)
            ->where('created_at', '>=', $now->subHours($window))
            ->count();

        $status = match (true) {
            $failedRecently > 0 => HealthStatus::Critical,
            $oldestPendingMinutes !== null && $oldestPendingMinutes >= (int) config('monitoring.store_health.outbox_stale_minutes') => HealthStatus::Warning,
            default => HealthStatus::Ok,
        };

        return [
            'status' => $status->value,
            'by_status' => $byStatus,
            'oldest_pending_minutes' => $oldestPendingMinutes,
            'failed_recently' => $failedRecently,
            'failed_jobs' => DB::table('failed_jobs')->count(),
        ];
    }

    /**
     * ADR-005 §17: "version usage should be observable so the sunset
     * decision for a deprecated version is evidence-based". Grouped by
     * the version segment of each logged Developer API path (e.g.
     * api/dev/v1/products -> v1).
     *
     * @return list<array{version: string, requests: int, stores: int, api_keys: int, server_errors: int, last_seen_at: ?string}>
     */
    public function apiUsageByVersion(int $days): array
    {
        $rows = ApiRequestLog::query()->withoutTenantScope()
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw(
                "COALESCE(REGEXP_SUBSTR(endpoint, 'v[0-9]+'), 'unversioned') as version, ".
                'COUNT(*) as requests, COUNT(DISTINCT store_id) as stores, COUNT(DISTINCT api_key_id) as api_keys, '.
                'SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END) as server_errors, MAX(created_at) as last_seen_at'
            )
            ->groupBy('version')
            ->orderBy('version')
            ->toBase()
            ->get();

        return $rows->map(fn ($row) => [
            'version' => (string) $row->version,
            'requests' => (int) $row->requests,
            'stores' => (int) $row->stores,
            'api_keys' => (int) $row->api_keys,
            'server_errors' => (int) $row->server_errors,
            'last_seen_at' => $row->last_seen_at !== null ? CarbonImmutable::parse($row->last_seen_at)->toIso8601String() : null,
        ])->values()->all();
    }
}
