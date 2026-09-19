<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Policies;

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

/**
 * Same two-layer model established in Phase B1's RolePolicy: ADR-001
 * Layers 3-4 already make a cross-tenant Product unreachable (404)
 * before this Policy ever runs; this Policy checks permission +
 * resource ownership within the user's own store.
 */
final class ProductPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->userHasPermission($user, 'products.view') || $this->isOwner($user);
    }

    public function view(User $user, Product $product): bool
    {
        return $this->belongsToUsersActiveStore($user, $product)
            && ($this->userHasPermission($user, 'products.view') || $this->isOwner($user));
    }

    public function create(User $user): bool
    {
        return $this->userHasPermission($user, 'products.create') || $this->isOwner($user);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->belongsToUsersActiveStore($user, $product)
            && ($this->userHasPermission($user, 'products.update') || $this->isOwner($user));
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->belongsToUsersActiveStore($user, $product)
            && ($this->userHasPermission($user, 'products.delete') || $this->isOwner($user));
    }

    /** Module 06 §25: cost price is restricted through permissions, never customer-visible. */
    public function viewCostPrice(User $user, Product $product): bool
    {
        return $this->belongsToUsersActiveStore($user, $product)
            && ($this->userHasPermission($user, 'products.view_cost') || $this->isOwner($user));
    }
}
