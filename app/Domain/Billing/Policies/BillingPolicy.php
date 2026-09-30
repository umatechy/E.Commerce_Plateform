<?php

declare(strict_types=1);

namespace App\Domain\Billing\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

/**
 * Module 29 — the store's side of platform billing. Financial, so both
 * abilities are Owner-only by default (the seeded Manager role gets
 * neither), like audit.view and backups.restore.
 */
final class BillingPolicy extends BaseTenantPolicy
{
    public function view(User $user): bool
    {
        return $this->userHasPermission($user, 'billing.view') || $this->isOwner($user);
    }

    public function manage(User $user): bool
    {
        return $this->userHasPermission($user, 'billing.manage') || $this->isOwner($user);
    }
}
