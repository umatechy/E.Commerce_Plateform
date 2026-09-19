<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Exceptions;

use App\Domain\Shipping\Models\ShipmentStatus;
use RuntimeException;

final class InvalidShipmentStateTransitionException extends RuntimeException
{
    public function __construct(public readonly ShipmentStatus $from, public readonly ShipmentStatus $to)
    {
        parent::__construct("Cannot transition shipment from [{$from->value}] to [{$to->value}].");
    }
}
