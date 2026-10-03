<?php

declare(strict_types=1);

namespace App\Domain\Customers\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

/**
 * Module 10 §85 "Admin API security": store membership, role,
 * permission and tenant. The permission keys come from Module 02's
 * catalog (PermissionSeeder); a Store Owner holds them all. Rows are
 * resolved under the tenant scope before this runs, so another store's
 * customer is already a 404.
 */
final class CustomerPolicy extends BaseTenantPolicy
{
    public function view(User $user): bool
    {
        return $this->userHasPermission($user, 'customers.view') || $this->isOwner($user);
    }

    public function manage(User $user): bool
    {
        return $this->userHasPermission($user, 'customers.manage') || $this->isOwner($user);
    }

    public function export(User $user): bool
    {
        return $this->userHasPermission($user, 'customers.export') || $this->isOwner($user);
    }

    public function import(User $user): bool
    {
        return $this->userHasPermission($user, 'customers.import') || $this->isOwner($user);
    }
}
