<?php

declare(strict_types=1);

namespace App\Domain\Payments\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;
use App\Domain\Payments\Models\Payment;

/**
 * Staff-facing authorization. Module 12 §65 "Payment Permissions" —
 * "high-risk financial permissions should be separately controlled":
 * `refund` is intentionally NOT granted to Manager by default
 * (StoreObserver) — only Owner (via isOwner()) or an explicitly
 * `payments.refund`-granted role.
 */
final class PaymentPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->userHasPermission($user, 'payments.view') || $this->isOwner($user);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->belongsToUsersActiveStore($user, $payment)
            && ($this->userHasPermission($user, 'payments.view') || $this->isOwner($user));
    }

    public function manage(User $user, Payment $payment): bool
    {
        return $this->belongsToUsersActiveStore($user, $payment)
            && ($this->userHasPermission($user, 'payments.manage') || $this->isOwner($user));
    }

    public function refund(User $user, Payment $payment): bool
    {
        return $this->belongsToUsersActiveStore($user, $payment)
            && ($this->userHasPermission($user, 'payments.refund') || $this->isOwner($user));
    }
}
