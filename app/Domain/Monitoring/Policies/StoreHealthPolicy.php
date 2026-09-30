<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

/** Module 24 — a store's own health and resource usage view. */
final class StoreHealthPolicy extends BaseTenantPolicy
{
    public function view(User $user): bool
    {
        return $this->userHasPermission($user, 'store_health.view') || $this->isOwner($user);
    }
}
