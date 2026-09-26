<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\Domains\Models\Domain;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Tenancy\Http\Resources\StoreResource;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Super Admin cross-tenant entry point (ADR-001 Layer 7).
 * `impersonate()`/`show()` are reached only via the
 * 'super_admin.impersonate' group (per-store); `index()` is
 * platform-global (Phase B16 fix — see
 * docs/development/b16-inspection-findings.md) and sits under the
 * separate 'super_admin.platform' group instead.
 */
final class SuperAdminStoreController
{
    /** Module 30 §9 "Store/Tenant Management" — search/list, platform-global (no target store). */
    public function index(Request $request): JsonResponse
    {
        $query = Store::query();

        if ($search = $request->string('search')->toString()) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%"));
        }

        return response()->json(['data' => $query->paginate(25)]);
    }

    /** Module 30 §9/§18/§25 — a single store's operational snapshot: subscription, domain, low-stock count. Reuses each domain's own authoritative data, never a duplicate calculation. */
    public function show(Store $store): JsonResponse
    {
        $subscription = Subscription::query()->withoutTenantScope()->where('store_id', $store->id)->latest()->first();
        $primaryDomain = Domain::query()->withoutTenantScope()->where('store_id', $store->id)->where('is_primary', true)->first();
        $lowStockCount = Inventory::query()->withoutTenantScope()->where('store_id', $store->id)
            ->whereNotNull('reorder_point')->whereRaw('(on_hand - reserved) <= reorder_point')->count();

        return response()->json(['data' => [
            'store' => new StoreResource($store),
            'subscription_status' => $subscription?->status->value,
            'package_code' => $subscription?->package?->code,
            'primary_domain' => $primaryDomain?->normalized_hostname,
            'low_stock_products' => $lowStockCount,
        ]]);
    }

    /**
     * Module 30 §27 "Privileged Support / Impersonation" — Phase B16
     * hardening: a `reason` is now REQUIRED (was previously absent —
     * see inspection findings "Second Finding"), included in the audit
     * trail alongside the existing generic middleware-level log entry.
     */
    public function impersonate(Request $request, Store $store): StoreResource
    {
        $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        // TenantContext is already resolved to $store (impersonation
        // mode) by EnsureSuperAdminImpersonation — this line exists only
        // to make that reliance explicit and reviewable, not to redo it.
        Log::channel('audit')->info('super_admin.store.impersonated', [
            'acting_super_admin_id' => $request->user()->id, 'target_store_id' => $store->id, 'reason' => $request->string('reason'),
        ]);

        return new StoreResource($store);
    }
}
