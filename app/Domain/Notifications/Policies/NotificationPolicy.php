<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

final class NotificationPolicy extends BaseTenantPolicy
{
    public function view(User $user): bool
    {
        return $this->userHasPermission($user, 'notifications.view') || $this->isOwner($user);
    }

    public function manage(User $user): bool
    {
        return $this->userHasPermission($user, 'notifications.manage') || $this->isOwner($user);
    }

    /** Phase B50 (Module 21 §59): the store's SMS and WhatsApp accounts — an elevated permission. */
    public function providers(User $user): bool
    {
        return $this->userHasPermission($user, 'messaging.providers') || $this->isOwner($user);
    }
}
