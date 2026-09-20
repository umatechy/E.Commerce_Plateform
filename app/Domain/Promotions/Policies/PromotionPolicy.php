<?php

declare(strict_types=1);

namespace App\Domain\Promotions\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;
use App\Domain\Promotions\Models\Promotion;

final class PromotionPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->userHasPermission($user, 'promotions.view') || $this->isOwner($user);
    }

    public function view(User $user, Promotion $promotion): bool
    {
        return $this->belongsToUsersActiveStore($user, $promotion)
            && ($this->userHasPermission($user, 'promotions.view') || $this->isOwner($user));
    }

    public function create(User $user): bool
    {
        return $this->userHasPermission($user, 'promotions.manage') || $this->isOwner($user);
    }

    public function manage(User $user, Promotion $promotion): bool
    {
        return $this->belongsToUsersActiveStore($user, $promotion)
            && ($this->userHasPermission($user, 'promotions.manage') || $this->isOwner($user));
    }
}
