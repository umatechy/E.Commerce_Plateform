<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

/**
 * Module 32 — both abilities are sensitive and granted to the Owner by
 * default only (the seeded Manager role gets neither, same precedent as
 * domains.manage / backups.restore).
 */
final class CompliancePolicy extends BaseTenantPolicy
{
    public function viewAuditLog(User $user): bool
    {
        return $this->userHasPermission($user, 'audit.view') || $this->isOwner($user);
    }

    public function managePrivacy(User $user): bool
    {
        return $this->userHasPermission($user, 'privacy.manage') || $this->isOwner($user);
    }
}
