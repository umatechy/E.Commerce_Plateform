<?php

declare(strict_types=1);

namespace App\Domain\Settings\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

final class SettingPolicy extends BaseTenantPolicy
{
    public function viewStore(User $user): bool
    {
        return $this->userHasPermission($user, 'settings.view') || $this->isOwner($user);
    }

    public function manageStore(User $user): bool
    {
        return $this->userHasPermission($user, 'settings.manage') || $this->isOwner($user);
    }
}
