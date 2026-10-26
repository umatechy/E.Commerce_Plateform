<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

/**
 * Phase P2: the store's loyalty program. Viewing and running it are
 * marketing permissions; giving or taking points by hand creates or removes
 * value that turns into store credit, so it is a financial one
 * (loyalty.adjust). The Owner holds all three.
 */
final class LoyaltyPolicy extends BaseTenantPolicy
{
    public function view(User $user): bool
    {
        return $this->userHasPermission($user, 'loyalty.view') || $this->isOwner($user);
    }

    public function manage(User $user): bool
    {
        return $this->userHasPermission($user, 'loyalty.manage') || $this->isOwner($user);
    }

    public function adjust(User $user): bool
    {
        return $this->userHasPermission($user, 'loyalty.adjust') || $this->isOwner($user);
    }
}
