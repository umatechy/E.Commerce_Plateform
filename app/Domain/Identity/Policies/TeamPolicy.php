<?php

declare(strict_types=1);

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Models\User;

/**
 * Who may see and change a store's team (Module 02 §18–19). These are the
 * coarse abilities; RoleGrants then decides which roles and members a
 * given actor may touch.
 */
final class TeamPolicy extends BaseTenantPolicy
{
    public function view(User $user): bool
    {
        return $this->isOwner($user) || $this->userHasPermission($user, 'users.view');
    }

    public function invite(User $user): bool
    {
        return $this->isOwner($user) || $this->userHasPermission($user, 'users.invite');
    }

    public function manage(User $user): bool
    {
        return $this->isOwner($user) || $this->userHasPermission($user, 'users.manage');
    }
}
