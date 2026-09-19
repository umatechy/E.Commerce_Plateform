<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;
use App\Domain\Inventory\Models\Warehouse;

final class WarehousePolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->userHasPermission($user, 'warehouses.manage') || $this->isOwner($user)
            || $this->userHasPermission($user, 'inventory.view');
    }

    public function manage(User $user, ?Warehouse $warehouse = null): bool
    {
        if ($warehouse !== null && ! $this->belongsToUsersActiveStore($user, $warehouse)) {
            return false;
        }

        return $this->userHasPermission($user, 'warehouses.manage') || $this->isOwner($user);
    }
}
