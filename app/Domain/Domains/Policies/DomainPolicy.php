<?php

declare(strict_types=1);

namespace App\Domain\Domains\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;
use App\Domain\Domains\Models\Domain;

/** Module 19 §32 "Customer Domain Management" — a store user may only ever act on their own store's domains. */
final class DomainPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->userHasPermission($user, 'domains.view') || $this->isOwner($user);
    }

    public function view(User $user, Domain $domain): bool
    {
        return $this->belongsToUsersActiveStore($user, $domain)
            && ($this->userHasPermission($user, 'domains.view') || $this->isOwner($user));
    }

    public function manage(User $user, ?Domain $domain = null): bool
    {
        if ($domain !== null && ! $this->belongsToUsersActiveStore($user, $domain)) {
            return false;
        }

        return $this->userHasPermission($user, 'domains.manage') || $this->isOwner($user);
    }
}
