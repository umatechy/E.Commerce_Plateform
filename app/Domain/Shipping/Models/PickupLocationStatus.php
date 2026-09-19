<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Models;

enum PickupLocationStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
