<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

/**
 * Module 05 — opening a store to the public is the Owner's decision;
 * `storefront.manage` can delegate it (the seeded Manager role does not
 * get it).
 */
final class StorefrontPolicy extends BaseTenantPolicy
{
    public function manage(User $user): bool
    {
        return $this->userHasPermission($user, 'storefront.manage') || $this->isOwner($user);
    }
}
