<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Http\Resources\UserResource;
use App\Domain\Settings\Services\StoreClock;
use App\Domain\Tenancy\Models\Store;
use App\Http\Support\AdminShellProps;
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
            // UI-1: the platform's name and logo (Super Admin → Platform settings), for every page.
            'brand' => fn () => [
                'name' => (string) (app(\App\Domain\Settings\Services\ConfigService::class)->get('platform.brand_name') ?? 'Umar Techy'),
                'logo_url' => app(\App\Domain\Settings\Services\ConfigService::class)->get('platform.logo_url'),
            ],
            'auth' => [
                'user' => $user ? (new UserResource($user))->resolve($request) : null,
                // Phase B51: with its lifecycle status, so the admin can say why it is read-only.
                'activeStore' => $activeStoreId ? $this->activeStore($activeStoreId) : null,
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
                // Phase B31 (G6): what the navigation needs to show only what this
                // user may open and what the package includes. Display only.
                ...app(AdminShellProps::class)->for($user, $activeStoreId),
                // Phase B54: newer release notes than this user has seen (the "What's new" mark).
                'help_unseen_release' => $user === null ? null : $this->unseenRelease($user->help_seen_release),
            ],
        ];
    }

    private function unseenRelease(?string $seen): ?string
    {
        $latest = app(\App\Domain\Help\Services\HelpLibrary::class)->latestRelease();

        return $latest !== null && $latest['version'] !== $seen ? $latest['version'] : null;
    }

    /** @return array{id: int, name: string, slug: string, status: string, retention_ends_at: ?string}|null */
    private function activeStore(int $id): ?array
    {
        $store = Store::query()->withoutGlobalScopes()->find($id);

        return $store === null ? null : [
            'id' => $store->id, 'name' => $store->name, 'slug' => $store->slug, 'status' => $store->status->value,
            'retention_ends_at' => $store->retention_ends_at?->toIso8601String(),
        ];
    }
}
