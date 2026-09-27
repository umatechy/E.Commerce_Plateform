<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

final class DeveloperPlatformPolicy extends BaseTenantPolicy
{
    public function view(User $user): bool
    {
        return $this->userHasPermission($user, 'developer_platform.view') || $this->isOwner($user);
    }

    public function manage(User $user): bool
    {
        return $this->userHasPermission($user, 'developer_platform.manage') || $this->isOwner($user);
    }
}
