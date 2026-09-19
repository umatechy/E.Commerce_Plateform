<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

/**
 * Not registered via Gate::policy() (no single model backs shipping
 * configuration — it spans ShippingZone/ShippingMethod/ShippingRate) —
 * ShippingConfigController instantiates this directly instead. Reuses
 * BaseTenantPolicy's userHasPermission()/isOwner() exactly like every
 * model-bound Policy in this codebase.
 */
final class ShippingConfigPolicy extends BaseTenantPolicy
{
    public function manage(User $user): bool
    {
        return $this->userHasPermission($user, 'shipping_config.manage') || $this->isOwner($user);
    }
}
