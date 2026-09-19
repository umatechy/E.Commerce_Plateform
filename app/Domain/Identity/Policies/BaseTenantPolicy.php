<?php

declare(strict_types=1);

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for every tenant-owned resource's Policy (Module 02, this
 * prompt's "RBAC / Policy Rules" section — authorization MUST be
 * server-side via Roles/Permissions/Policies/tenant membership/resource
 * ownership/package entitlements).
 *
 * ADR-001 Layer 3/4 already make a cross-tenant row unreachable (404) at
 * the query/route-binding layer. Policies are the SEPARATE, independent
 * layer that then asks "given this resource IS in my tenant, does THIS
 * user have permission to do THIS action on it" — defense-in-depth: a
 * bug in one layer does not remove the other.
 */
abstract class BaseTenantPolicy
{
    /**
     * Subclasses implement the specific permission key this policy's
     * actions map to (e.g. 'products.update'), looked up against the
     * user's role permissions within their active store.
     */
    protected function userHasPermission(User $user, string $permissionKey): bool
    {
        $storeId = $user->activeStoreId();

        if ($storeId === null) {
            return false;
        }

        $roleId = $user->stores()->wherePivot('store_id', $storeId)->value('store_user.role_id');

        if ($roleId === null) {
            return false;
        }

        return \App\Domain\Identity\Models\Role::query()
            ->whereKey($roleId)
            ->whereHas('permissions', fn ($q) => $q->where('key', $permissionKey))
            ->exists();
    }

    /**
     * Defense-in-depth ownership re-check (ADR-001 Layer 4), used inside
     * concrete policy methods alongside the permission check — even
     * though the global scope already prevents cross-tenant rows from
     * resolving, this makes the guarantee explicit and testable at the
     * policy layer too.
     */
    protected function belongsToUsersActiveStore(User $user, Model $resource): bool
    {
        return isset($resource->store_id) && $resource->store_id === $user->activeStoreId();
    }

    /**
     * The store's Owner role always has full access within their own
     * store, without needing every individual permission key granted
     * explicitly (StoreObserver seeds Owner with no permission rows at
     * all — see its docblock). Centralized here (Phase B3 fix — this
     * was duplicated per-Policy in RolePolicy and would have been
     * duplicated again in every new Catalog Policy; moved to the base
     * class instead, per the "avoid duplicated business logic" rule
     * every milestone's prompt repeats).
     */
    protected function isOwner(User $user): bool
    {
        $storeId = $user->activeStoreId();

        if ($storeId === null) {
            return false;
        }

        $roleId = $user->stores()->wherePivot('store_id', $storeId)->value('store_user.role_id');

        return $roleId !== null && \App\Domain\Identity\Models\Role::query()->withoutTenantScope()
            ->whereKey($roleId)
            ->where('slug', 'owner')
            ->exists();
    }
}
