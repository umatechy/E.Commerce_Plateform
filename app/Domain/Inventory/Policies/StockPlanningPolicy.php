<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

/**
 * Phase P4a: stock planning, receiving and transfers use the existing
 * stock permissions — `inventory.view` to look, `inventory.adjust` to move
 * stock (Phase B4).
 */
final class StockPlanningPolicy extends BaseTenantPolicy
{
    public function view(User $user): bool
    {
        return $this->userHasPermission($user, 'inventory.view') || $this->isOwner($user);
    }

    public function manage(User $user): bool
    {
        return $this->userHasPermission($user, 'inventory.adjust') || $this->isOwner($user);
    }
}
