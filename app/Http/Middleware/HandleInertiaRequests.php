<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Http\Resources\UserResource;
use App\Domain\Settings\Services\StoreClock;
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
                'user' => $user ? (new UserResource($user))->resolve($request) : null,
                'activeStore' => $activeStoreId
                    ? Store::query()->withoutGlobalScopes()->find($activeStoreId)?->only(['id', 'name', 'slug'])
                    : null,
                // The timezone admin pages show dates in (Module 33 §50.3):
                // the store's, or UTC when no store is in context.
                'timezone' => app(StoreClock::class)->timezoneName(),
                // True while EnsureRequiredMfa keeps this user out of the Store
                // Admin: a Store Owner who has not turned on two-step sign-in.
                // The layout uses it to say so, instead of letting every link
                // bounce back to the Security page without a word.
                'mfa_enrollment_required' => $user !== null
                    && (bool) config('security.mfa.required_for_store_owners')
                    && ! $user->hasMfaEnabled()
                    && $user->ownsAStore(),
            ],
        ];
    }
}
