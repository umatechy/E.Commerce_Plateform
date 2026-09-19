<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Policies;

use App\Domain\Catalog\Models\Category;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

final class CategoryPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->userHasPermission($user, 'categories.manage') || $this->isOwner($user)
            || $this->userHasPermission($user, 'products.view'); // read-only catalog browsing needs category names too
    }

    public function manage(User $user, ?Category $category = null): bool
    {
        if ($category !== null && ! $this->belongsToUsersActiveStore($user, $category)) {
            return false;
        }

        return $this->userHasPermission($user, 'categories.manage') || $this->isOwner($user);
    }
}
