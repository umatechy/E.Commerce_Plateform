<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\MembershipStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\StoreMembership;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\SystemRoles;

/**
 * The privilege-escalation rules for team management (Module 02 §17–18,
 * §21–22), evaluated in the current tenant:
 *
 *  - Nobody can grant the Owner role (ownership transfer is a separate,
 *    not-yet-built high-security workflow).
 *  - The Owner may grant any other role and act on any other member.
 *  - Anyone else may only grant a role whose permissions are a subset of
 *    their own, and may only act on members whose role is a subset of
 *    their own ("the inviter must not be able to grant permissions beyond
 *    their own allowed authority").
 *  - Nobody acts on their own membership or on the Owner's.
 */
final class RoleGrants
{
    /** @var array<int, list<string>> */
    private array $keys = [];

    public function isOwnerRole(?Role $role): bool
    {
        return $role !== null && $role->slug === SystemRoles::OWNER;
    }

    public function actorMembership(User $actor): ?StoreMembership
    {
        return StoreMembership::query()->where('user_id', $actor->id)->where('status', MembershipStatus::Active)->with('role')->first();
    }

    public function canGrant(User $actor, Role $target): bool
    {
        if ($this->isOwnerRole($target)) {
            return false;
        }

        $actorRole = $this->actorMembership($actor)?->role;

        return $this->isOwnerRole($actorRole) || ($actorRole !== null && $this->isSubset($target, $actorRole));
    }

    public function canActOn(User $actor, StoreMembership $member): bool
    {
        if ($member->user_id === $actor->id || $this->isOwnerRole($member->role)) {
            return false;
        }

        $actorRole = $this->actorMembership($actor)?->role;

        if ($this->isOwnerRole($actorRole)) {
            return true;
        }

        // A member without a role has no permissions, so anyone with the ability may act on them.
        return $actorRole !== null && ($member->role === null || $this->isSubset($member->role, $actorRole));
    }

    /** @return list<string> */
    public function permissionKeys(Role $role): array
    {
        return $this->keys[$role->id] ??= $role->permissions()->pluck('key')->sort()->values()->all();
    }

    private function isSubset(Role $role, Role $of): bool
    {
        return array_diff($this->permissionKeys($role), $this->permissionKeys($of)) === [];
    }
}
