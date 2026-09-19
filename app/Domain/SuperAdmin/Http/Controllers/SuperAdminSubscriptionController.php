<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\Packages\Http\Resources\SubscriptionResource;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Services\SubscriptionLifecycleService;
use App\Domain\SuperAdmin\Http\Requests\ChangeStorePackageRequest;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super Admin subscription administration (Module 04 "Subscription
 * Administration": "Super Admin operations are explicitly authorized" —
 * enforced by this route group's 'can:super-admin.impersonate' +
 * 'super_admin.impersonate' middleware pair, identical to
 * SuperAdminStoreController's pattern from Phase B1, deliberately reused
 * rather than inventing a second Super-Admin gate mechanism).
 */
final class SuperAdminSubscriptionController
{
    public function changePackage(
        ChangeStorePackageRequest $request,
        Store $store,
        SubscriptionLifecycleService $subscriptions,
    ): JsonResponse {
        $newPackage = Package::query()->where('code', $request->string('package_code'))->firstOrFail();

        $overLimit = $subscriptions->changePackage(
            $store,
            $newPackage,
            actorDescription: $request->user()->email,
            source: 'super_admin',
        );

        return response()->json([
            'data' => [
                'store_id' => $store->id,
                'new_package_code' => $newPackage->code,
                'over_limit' => $overLimit, // Module 04 §22 — informational only, never auto-destructive
            ],
        ]);
    }

    public function suspend(Request $request, Store $store, SubscriptionLifecycleService $subscriptions): JsonResponse
    {
        $subscriptions->suspend($store, $request->input('reason', 'manual_super_admin_action'));

        return response()->json(status: 204);
    }

    public function reactivate(Request $request, Store $store, SubscriptionLifecycleService $subscriptions): JsonResponse
    {
        $subscriptions->reactivate($store, $request->input('reason', 'manual_super_admin_action'));

        return response()->json(status: 204);
    }
}
