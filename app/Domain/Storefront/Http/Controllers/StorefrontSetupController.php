<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Http\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Storefront\Policies\StorefrontPolicy;
use App\Domain\Storefront\Services\StorefrontSetupService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 05 — staff side of the storefront: the launch checklist and the
 * launch. `storefront.manage` or the Owner (the seeded Manager role does
 * not get it: opening a store to the public is the owner's decision).
 */
final class StorefrontSetupController
{
    public function show(Request $request, TenantContext $context, StorefrontSetupService $setup): JsonResponse
    {
        $this->authorize($request->user());

        return response()->json(['data' => $setup->status(Store::query()->findOrFail($context->storeId()))]);
    }

    public function launch(Request $request, TenantContext $context, StorefrontSetupService $setup): JsonResponse
    {
        $this->authorize($request->user());
        $store = Store::query()->findOrFail($context->storeId());
        $result = $setup->launch($store);

        if (! $result['ok']) {
            $status = $result['code'] === 'setup_incomplete' ? 422 : 409;

            return response()->json([
                'message' => match ($result['code']) {
                    'setup_incomplete' => 'Complete the required steps before launching.',
                    'already_launched' => 'The store is already live.',
                    default => 'This store cannot be launched in its current state.',
                },
                'code' => $result['code'],
                'checks' => $result['checks'] ?? null,
            ], $status);
        }

        return response()->json(['data' => $setup->status($store->refresh())]);
    }

    private function authorize(User $user): void
    {
        abort_unless(app(StorefrontPolicy::class)->manage($user), 403);
    }
}
