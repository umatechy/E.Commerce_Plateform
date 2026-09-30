<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\Monitoring\Http\Resources\StoreHealthSnapshotResource;
use App\Domain\Monitoring\Models\HealthStatus;
use App\Domain\Monitoring\Models\StoreHealthSnapshot;
use App\Domain\Monitoring\Services\PlatformMonitoringService;
use App\Domain\Monitoring\Services\StoreHealthService;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Module 24 Super Admin monitoring (Phase B21). storeHealthOverview(),
 * outbox() and apiUsage() sit in the super_admin.platform group (no
 * target store); storeHealth() sits in the impersonation group, which
 * has already resolved the tenant context to {store} and audit-logged it.
 */
final class SuperAdminMonitoringController
{
    public function storeHealthOverview(Request $request, PlatformMonitoringService $monitoring): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(HealthStatus::class)],
        ]);

        $snapshots = $monitoring->storeHealthOverview(isset($validated['status']) ? HealthStatus::from($validated['status']) : null);

        return response()->json([
            'data' => $snapshots->through(fn (StoreHealthSnapshot $snapshot) => (new StoreHealthSnapshotResource($snapshot))->resolve($request)),
        ]);
    }

    /** Live evaluation of one store, for support investigations. */
    public function storeHealth(Store $store, StoreHealthService $health): JsonResponse
    {
        return response()->json(['data' => $health->evaluate($store)->toArray()]);
    }

    public function outbox(PlatformMonitoringService $monitoring): JsonResponse
    {
        return response()->json(['data' => $monitoring->outboxBacklog()]);
    }

    public function apiUsage(Request $request, PlatformMonitoringService $monitoring): JsonResponse
    {
        $validated = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:90']]);
        $days = (int) ($validated['days'] ?? 30);

        return response()->json(['data' => ['days' => $days, 'versions' => $monitoring->apiUsageByVersion($days)]]);
    }
}
