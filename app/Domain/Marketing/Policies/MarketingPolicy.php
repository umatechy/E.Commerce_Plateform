<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;
use App\Domain\Marketing\Models\Campaign;

final class MarketingPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->userHasPermission($user, 'marketing.view') || $this->isOwner($user);
    }

    public function view(User $user, Campaign $campaign): bool
    {
        return $this->belongsToUsersActiveStore($user, $campaign)
            && ($this->userHasPermission($user, 'marketing.view') || $this->isOwner($user));
    }

    public function manage(User $user, ?Campaign $campaign = null): bool
    {
        if ($campaign !== null && ! $this->belongsToUsersActiveStore($user, $campaign)) {
            return false;
        }

        return $this->userHasPermission($user, 'marketing.manage') || $this->isOwner($user);
    }
}
