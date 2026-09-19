<?php

declare(strict_types=1);

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;

/**
 * First concrete Policy required by B1 (this milestone's "Policies"
 * section). Note what this Policy does NOT need to re-check: ADR-001
 * Layers 3–4 already make it impossible for $role to be a different
 * tenant's row by the time it reaches here (route-model binding + the
 * global scope resolve it, or it 404s before a Policy method ever
 * runs) — this Policy's job is purely "given this IS my tenant's role,
 * does this user have permission to act on it", the second,
 * independent layer per BaseTenantPolicy's docblock.
 */
final class RolePolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->userHasPermission($user, 'roles.view') || $this->isOwner($user);
    }

    public function view(User $user, Role $role): bool
    {
        return $this->belongsToUsersActiveStore($user, $role)
            && ($this->userHasPermission($user, 'roles.view') || $this->isOwner($user));
    }

    public function create(User $user): bool
    {
        return $this->isOwner($user); // role creation is Owner-only for B1 scope
    }

    public function update(User $user, Role $role): bool
    {
        if (! $this->belongsToUsersActiveStore($user, $role)) {
            return false;
        }

        if ($role->is_system) {
            return false; // seeded default roles (owner/manager/staff) are not editable
        }

        return $this->isOwner($user);
    }

    public function delete(User $user, Role $role): bool
    {
        if (! $this->belongsToUsersActiveStore($user, $role)) {
            return false;
        }

        if ($role->is_system) {
            return false; // never deletable — see StoreObserver
        }

        return $this->isOwner($user);
    }
}
