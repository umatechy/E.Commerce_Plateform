<?php

declare(strict_types=1);

namespace App\Domain\Theme\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

final class ThemePolicy extends BaseTenantPolicy
{
    public function view(User $user): bool
    {
        return $this->userHasPermission($user, 'theme.view') || $this->isOwner($user);
    }

    public function manage(User $user): bool
    {
        return $this->userHasPermission($user, 'theme.manage') || $this->isOwner($user);
    }

    public function publish(User $user): bool
    {
        return $this->userHasPermission($user, 'theme.publish') || $this->isOwner($user);
    }
}
