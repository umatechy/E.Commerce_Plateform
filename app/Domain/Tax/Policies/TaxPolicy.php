<?php

declare(strict_types=1);

namespace App\Domain\Tax\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;
use App\Domain\Tax\Models\TaxClass;

/**
 * Phase B46: tax set-up is a financial and legal setting — changed with
 * tax.manage (Owner, Administrator); seen also with settings.view. Product
 * staff choose a product's tax class with products.update (ProductPolicy),
 * from the list they may read here.
 */
final class TaxPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->userHasPermission($user, 'tax.manage') || $this->userHasPermission($user, 'settings.view')
            || $this->userHasPermission($user, 'products.update') || $this->isOwner($user);
    }

    public function manage(User $user, ?TaxClass $class = null): bool
    {
        if ($class !== null && ! $this->belongsToUsersActiveStore($user, $class)) {
            return false;
        }

        return $this->userHasPermission($user, 'tax.manage') || $this->isOwner($user);
    }
}
