<?php

declare(strict_types=1);

namespace App\Domain\StoreCredit\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

/**
 * Module 09 §52 "protected from unauthorized manipulation":
 * `store_credit.manage` — give or take back store credit by hand. A
 * Store Owner holds every permission. Seeing a balance is part of
 * seeing the customer (`customers.view`, CustomerPolicy).
 */
final class StoreCreditPolicy extends BaseTenantPolicy
{
    public function manage(User $user): bool
    {
        return $this->userHasPermission($user, 'store_credit.manage') || $this->isOwner($user);
    }
}
