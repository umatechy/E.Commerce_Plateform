<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;
use App\Domain\Inventory\Models\Inventory;

final class InventoryPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->userHasPermission($user, 'inventory.view') || $this->isOwner($user);
    }

    public function view(User $user, Inventory $inventory): bool
    {
        return $this->belongsToUsersActiveStore($user, $inventory)
            && ($this->userHasPermission($user, 'inventory.view') || $this->isOwner($user));
    }

    public function adjust(User $user, Inventory $inventory): bool
    {
        return $this->belongsToUsersActiveStore($user, $inventory)
            && ($this->userHasPermission($user, 'inventory.adjust') || $this->isOwner($user));
    }
}
