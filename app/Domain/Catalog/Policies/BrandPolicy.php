<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Policies;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

final class BrandPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->userHasPermission($user, 'brands.manage') || $this->isOwner($user)
            || $this->userHasPermission($user, 'products.view');
    }

    public function manage(User $user, ?Brand $brand = null): bool
    {
        if ($brand !== null && ! $this->belongsToUsersActiveStore($user, $brand)) {
            return false;
        }

        return $this->userHasPermission($user, 'brands.manage') || $this->isOwner($user);
    }
}
