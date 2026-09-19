<?php

declare(strict_types=1);

namespace App\Domain\Packages\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\Subscription;

/**
 * "Subscription Administration": authorized users can view their OWN
 * store's subscription; tenant isolation applies (enforced structurally
 * by BelongsToTenant — a cross-tenant Subscription ID already 404s
 * before this Policy runs, per ADR-001 Layers 3–4); ordinary users
 * cannot modify subscription state (only Super Admin, via a separate
 * platform-level controller/policy, can).
 */
final class SubscriptionPolicy
{
    public function view(User $user, Subscription $subscription): bool
    {
        return $subscription->store_id === $user->activeStoreId();
    }

    public function manage(User $user): bool
    {
        return $user->isPlatformStaff();
    }
}
