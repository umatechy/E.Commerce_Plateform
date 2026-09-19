<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Http\Resources\UserResource;
use App\Domain\Tenancy\Models\Store;
use Inertia\Middleware;

/**
 * Shares authenticated user + current store on every Inertia response
 * (consumed by resources/js/Layouts/AuthenticatedLayout.tsx). This is a
 * READ-ONLY, display-purpose share — it is never treated as an
 * authorization source; every server-side authorization check
 * (RolePolicy, EntitlementService) re-verifies independently against the
 * database on each request, never trusts what was previously shared to
 * the client (this milestone's "frontend checks are UI helpers only").
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(\Illuminate\Http\Request $request): array
    {
        $user = $request->user();
        $activeStoreId = $user?->activeStoreId();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? new UserResource($user) : null,
                'activeStore' => $activeStoreId
                    ? Store::query()->withoutGlobalScopes()->find($activeStoreId)?->only(['id', 'name'])
                    : null,
            ],
        ];
    }
}
