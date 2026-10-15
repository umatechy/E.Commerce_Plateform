<?php

declare(strict_types=1);

namespace App\Domain\Affiliates\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

/**
 * Phase B57 (Module 28 §5.8, §111): the store's affiliate program. Viewing
 * and running it are marketing permissions; paying affiliates and
 * correcting balances is a financial one (affiliates.payouts). The Owner
 * holds all three.
 */
final class AffiliatePolicy extends BaseTenantPolicy
{
    public function view(User $user): bool
    {
        return $this->userHasPermission($user, 'affiliates.view') || $this->isOwner($user);
    }

    public function manage(User $user): bool
    {
        return $this->userHasPermission($user, 'affiliates.manage') || $this->isOwner($user);
    }

    public function payouts(User $user): bool
    {
        return $this->userHasPermission($user, 'affiliates.payouts') || $this->isOwner($user);
    }
}
