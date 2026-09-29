<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Console;

use App\Domain\Monitoring\Models\HealthStatus;
use App\Domain\Monitoring\Services\StoreHealthService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreStatus;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Module 24 (Phase B21): records every live store's health once per run
 * (scheduled hourly in routes/console.php) and prunes old history.
 * Cancelled/archived stores are skipped — they have no operations left
 * to monitor.
 */
final class SnapshotStoreHealthCommand extends Command
{
    protected $signature = 'store-health:snapshot';

    protected $description = 'Record a health snapshot for every live store and prune old snapshots (Module 24).';

    public function handle(StoreHealthService $health, TenantContext $context): int
    {
        $counts = ['ok' => 0, 'warning' => 0, 'critical' => 0, 'errors' => 0];

        Store::query()
            ->whereNotIn('status', [StoreStatus::Cancelled->value, StoreStatus::Archived->value])
            ->orderBy('id')
            ->chunkById(100, function ($stores) use ($health, $context, &$counts) {
                foreach ($stores as $store) {
                    // Each store is evaluated strictly inside its own tenant
                    // context (ADR-001), exactly like one of its requests.
                    $context->resolveToStore($store->id);

                    try {
                        $counts[$health->snapshot($store)->overall_status->value]++;
                    } catch (\Throwable $e) {
                        // One broken store must not stop the others from
                        // being monitored; the failure itself is logged.
                        $counts['errors']++;
                        Log::error('store_health.snapshot_failed', ['store_id' => $store->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        $pruned = $health->pruneSnapshots();

        $this->info(sprintf(
            'Store health recorded: %d ok, %d warning, %d critical, %d failed; %d old snapshot(s) pruned.',
            $counts[HealthStatus::Ok->value], $counts[HealthStatus::Warning->value], $counts[HealthStatus::Critical->value], $counts['errors'], $pruned,
        ));

        return $counts['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
