<?php

declare(strict_types=1);

namespace App\Domain\Packages\Http\Controllers;

use App\Domain\Packages\Http\Resources\SubscriptionResource;
use App\Domain\Packages\Http\Resources\UsageOverviewResource;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Services\EntitlementService;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\Request;

/**
 * The authenticated store's OWN subscription + usage overview (Module
 * 04 §40–41). Tenant-safe by construction: the subscription resolved
 * here is ALWAYS the authenticated user's own active store's — never a
 * client-supplied store/subscription ID (this milestone: "Store A
 * cannot access Store B subscription").
 */
final class SubscriptionController
{
    public function show(Request $request, EntitlementService $entitlements): SubscriptionResource
    {
        $storeId = $request->user()->activeStoreId();
        abort_if($storeId === null, 403, 'No active store selected.');

        $store = Store::query()->findOrFail($storeId);
        $subscription = $store->currentSubscription()->withoutTenantScope()->with('package.entitlements')->firstOrFail();

        \Illuminate\Support\Facades\Gate::forUser($request->user())->authorize('view', $subscription);

        return new SubscriptionResource($subscription);
    }

    public function usage(Request $request, EntitlementService $entitlements): UsageOverviewResource
    {
        $storeId = $request->user()->activeStoreId();
        abort_if($storeId === null, 403, 'No active store selected.');

        $store = Store::query()->findOrFail($storeId);
        $subscription = $store->currentSubscription()->withoutTenantScope()->with('package.entitlements')->firstOrFail();

        $overview = [];

        foreach ($subscription->package->entitlements as $entitlement) {
            if ($entitlement->type !== EntitlementType::UsageLimit) {
                continue;
            }

            $overview[$entitlement->key] = [
                'limit' => $entitlements->limitFor($entitlement->key),
                'current' => $entitlements->currentUsage($entitlement->key),
                'unlimited' => $entitlements->isUnlimited($entitlement->key),
                'remaining' => $entitlements->remaining($entitlement->key),
            ];
        }

        return new UsageOverviewResource($overview);
    }
}
