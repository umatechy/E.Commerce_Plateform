<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

/**
 * Module 23 Phase 17 "Restore Authorization" — Non-Negotiable: "a user
 * who can view backups must not automatically be allowed to restore
 * them." `restore` is a deliberately SEPARATE, stronger permission
 * from `manage` — never implied by it.
 */
final class BackupPolicy extends BaseTenantPolicy
{
    public function view(User $user): bool
    {
        return $this->userHasPermission($user, 'backups.view') || $this->isOwner($user);
    }

    public function manage(User $user): bool
    {
        return $this->userHasPermission($user, 'backups.manage') || $this->isOwner($user);
    }

    public function requestRestore(User $user): bool
    {
        return $this->userHasPermission($user, 'backups.restore') || $this->isOwner($user);
    }
}
