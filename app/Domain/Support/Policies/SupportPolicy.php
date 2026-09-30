<?php

declare(strict_types=1);

namespace App\Domain\Support\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

/**
 * Module 34 — store staff abilities (the Owner has all of them):
 *  support.view    read the store's support inbox
 *  support.reply   reply, add internal notes, change status
 *  support.manage  assign, re-prioritise, re-categorise
 *  support.platform  talk to the platform's support team for the store
 */
final class SupportPolicy extends BaseTenantPolicy
{
    public function view(User $user): bool
    {
        return $this->can($user, 'support.view') || $this->can($user, 'support.reply') || $this->can($user, 'support.manage');
    }

    public function reply(User $user): bool
    {
        return $this->can($user, 'support.reply') || $this->can($user, 'support.manage');
    }

    public function manage(User $user): bool
    {
        return $this->can($user, 'support.manage');
    }

    public function platform(User $user): bool
    {
        return $this->can($user, 'support.platform');
    }

    private function can(User $user, string $permission): bool
    {
        return $this->userHasPermission($user, $permission) || $this->isOwner($user);
    }
}
