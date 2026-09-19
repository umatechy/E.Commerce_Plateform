<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;
use App\Domain\Shipping\Models\Shipment;

final class ShipmentPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->userHasPermission($user, 'shipments.view') || $this->isOwner($user);
    }

    public function view(User $user, Shipment $shipment): bool
    {
        return $this->belongsToUsersActiveStore($user, $shipment)
            && ($this->userHasPermission($user, 'shipments.view') || $this->isOwner($user));
    }

    public function create(User $user): bool
    {
        return $this->userHasPermission($user, 'shipments.fulfill') || $this->isOwner($user);
    }

    public function manage(User $user, Shipment $shipment): bool
    {
        return $this->belongsToUsersActiveStore($user, $shipment)
            && ($this->userHasPermission($user, 'shipments.fulfill') || $this->isOwner($user));
    }
}
