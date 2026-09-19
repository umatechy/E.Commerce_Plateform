<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Policies;

use App\Domain\Catalog\Models\Attribute;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

final class AttributePolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->userHasPermission($user, 'attributes.manage') || $this->isOwner($user)
            || $this->userHasPermission($user, 'products.view');
    }

    public function manage(User $user, ?Attribute $attribute = null): bool
    {
        if ($attribute !== null && ! $this->belongsToUsersActiveStore($user, $attribute)) {
            return false;
        }

        return $this->userHasPermission($user, 'attributes.manage') || $this->isOwner($user);
    }
}
