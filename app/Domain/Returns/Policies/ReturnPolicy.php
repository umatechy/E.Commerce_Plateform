<?php

declare(strict_types=1);

namespace App\Domain\Returns\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

/**
 * Module 09 §65 "Admin Permissions": Manage Returns, Approve Returns and
 * Process Refunds are separate. Refunds keep the permission they have had
 * since B7 (`payments.refund`, a high-risk financial permission). A Store
 * Owner holds them all. Rows are resolved under the tenant scope before
 * this runs, so another store's return is already a 404.
 */
final class ReturnPolicy extends BaseTenantPolicy
{
    public function view(User $user): bool
    {
        return $this->userHasPermission($user, 'returns.view') || $this->isOwner($user);
    }

    /** Record a return for a customer, mark it sent, receive and inspect the goods, make the replacement order. */
    public function manage(User $user): bool
    {
        return $this->userHasPermission($user, 'returns.manage') || $this->isOwner($user);
    }

    /** Approve or reject a request, and decide the refund amount. */
    public function approve(User $user): bool
    {
        return $this->userHasPermission($user, 'returns.approve') || $this->isOwner($user);
    }

    /** Pay the money back. */
    public function refund(User $user): bool
    {
        return $this->userHasPermission($user, 'payments.refund') || $this->isOwner($user);
    }
}
