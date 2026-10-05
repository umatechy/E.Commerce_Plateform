<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Policies;

use App\Domain\Catalog\Models\Badge;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

/**
 * Phase B43 — Module 06 §36: the store's badges are merchandising, like
 * collections and tags: seen with products.view, defined with
 * collections.manage (Administrator, Manager, Content & Marketing, Owner).
 * Putting a badge on a product is a product change (products.update).
 */
final class BadgePolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->userHasPermission($user, 'products.view') || $this->userHasPermission($user, 'collections.manage') || $this->isOwner($user);
    }

    public function manage(User $user, ?Badge $badge = null): bool
    {
        if ($badge !== null && ! $this->belongsToUsersActiveStore($user, $badge)) {
            return false;
        }

        return $this->userHasPermission($user, 'collections.manage') || $this->isOwner($user);
    }
}
