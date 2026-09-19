<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Support;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Session;

/**
 * Server-verified "current store" selection for multi-store users.
 *
 * Fixes the B0 gap noted in docs/development/b1-inspection-findings.md item B:
 * a user with memberships in more than one store needs an explicit, persisted
 * "which store am I currently acting as" selection — this is NEVER read from
 * a per-request client parameter (ADR-001 §8 Layer 1); it is verified against
 * real membership on every switch and then persisted server-side in the
 * authenticated session, exactly like any other piece of session state.
 */
final class StoreSwitcher
{
    private const SESSION_KEY = 'active_store_id';

    /**
     * @throws \App\Domain\Tenancy\Exceptions\UnauthorizedStoreSwitchException
     */
    public function switchTo(User $user, int $storeId): void
    {
        $membership = $user->stores()
            ->wherePivot('store_id', $storeId)
            ->wherePivot('status', 'active')
            ->first();

        if ($membership === null) {
            // Deliberately generic: do not reveal whether the store exists at
            // all if the user has no active membership to it (avoids leaking
            // tenant existence to an unauthorized user).
            throw new \App\Domain\Tenancy\Exceptions\UnauthorizedStoreSwitchException($storeId);
        }

        Session::put(self::SESSION_KEY, $storeId);
    }

    /**
     * Resolves the verified current store for this authenticated session.
     * Falls back to the user's single active membership when they belong to
     * exactly one store and have not made an explicit selection yet — this
     * is a usability default, not a trust shortcut: it still only ever
     * resolves to a store the user is actually an active member of.
     */
    public function currentStoreId(User $user): ?int
    {
        $sessionStoreId = Session::get(self::SESSION_KEY);

        if ($sessionStoreId !== null) {
            $stillValid = $user->stores()
                ->wherePivot('store_id', $sessionStoreId)
                ->wherePivot('status', 'active')
                ->exists();

            if ($stillValid) {
                return (int) $sessionStoreId;
            }

            // Membership was revoked/suspended since the session value was
            // set — never trust stale session state over current membership.
            Session::forget(self::SESSION_KEY);
        }

        $activeMemberships = $user->stores()->wherePivot('status', 'active')->pluck('stores.id');

        return $activeMemberships->count() === 1 ? (int) $activeMemberships->first() : null;
    }
}
