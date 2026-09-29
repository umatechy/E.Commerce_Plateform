<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Http\Controllers;

use App\Domain\Monitoring\Http\Resources\StoreHealthSnapshotResource;
use App\Domain\Monitoring\Models\StoreHealthSnapshot;
use App\Domain\Monitoring\Policies\StoreHealthPolicy;
use App\Domain\Monitoring\Services\StoreHealthService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 24 — a store's own health (Phase B21). Always the CURRENT
 * tenant's store, resolved server-side (ADR-001); there is no store
 * parameter to tamper with.
 */
final class StoreHealthController
{
    /** Live evaluation — always current, never a stale snapshot. */
    public function show(Request $request, StoreHealthService $health, TenantContext $context): JsonResponse
    {
        abort_unless(app(StoreHealthPolicy::class)->view($request->user()), 403);

        $store = Store::query()->findOrFail($context->storeId());

        return response()->json(['data' => $health->evaluate($store)->toArray()]);
    }

    /** The store's recorded snapshots (hourly), newest first. */
    public function history(Request $request): JsonResponse
    {
        abort_unless(app(StoreHealthPolicy::class)->view($request->user()), 403);

        $snapshots = StoreHealthSnapshot::query()->orderByDesc('created_at')->orderByDesc('id')->paginate(48);

        return response()->json([
            'data' => $snapshots->through(fn (StoreHealthSnapshot $snapshot) => (new StoreHealthSnapshotResource($snapshot))->resolve($request)),
        ]);
    }
}
