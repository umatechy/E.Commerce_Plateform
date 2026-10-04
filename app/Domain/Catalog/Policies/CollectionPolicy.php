<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Policies;

use App\Domain\Catalog\Models\Collection;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

/** Phase B39 — collections and tags (Module 06 §34–35): view with products.view, change with collections.manage. */
final class CollectionPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->userHasPermission($user, 'products.view') || $this->userHasPermission($user, 'collections.manage') || $this->isOwner($user);
    }

    public function manage(User $user, ?Collection $collection = null): bool
    {
        if ($collection !== null && ! $this->belongsToUsersActiveStore($user, $collection)) {
            return false;
        }

        return $this->userHasPermission($user, 'collections.manage') || $this->isOwner($user);
    }
}
