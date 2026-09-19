<?php

declare(strict_types=1);

namespace App\Domain\Orders\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;
use App\Domain\Orders\Models\Order;

/**
 * Staff-facing authorization (B5's API is staff-only — see
 * docs/development/b5-inspection-findings.md "Scope Decision" on the
 * deferred customer-facing boundary). Same two-layer model as every
 * other Policy since Phase B1: ADR-001 already makes a cross-tenant
 * Order unreachable (404); this Policy checks permission/ownership
 * within the user's own store.
 */
final class OrderPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->userHasPermission($user, 'orders.view') || $this->isOwner($user);
    }

    public function view(User $user, Order $order): bool
    {
        return $this->belongsToUsersActiveStore($user, $order)
            && ($this->userHasPermission($user, 'orders.view') || $this->isOwner($user));
    }

    public function create(User $user): bool
    {
        return $this->userHasPermission($user, 'orders.create') || $this->isOwner($user);
    }

    public function cancel(User $user, Order $order): bool
    {
        return $this->belongsToUsersActiveStore($user, $order)
            && ($this->userHasPermission($user, 'orders.cancel') || $this->isOwner($user));
    }
}
