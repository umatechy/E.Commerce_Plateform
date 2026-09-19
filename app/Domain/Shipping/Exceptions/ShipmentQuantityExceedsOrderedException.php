<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Exceptions;

use RuntimeException;

final class ShipmentQuantityExceedsOrderedException extends RuntimeException
{
    public function __construct(public readonly int $requested, public readonly int $remaining)
    {
        parent::__construct("Cannot ship {$requested} units — only {$remaining} remain unfulfilled for this order item.");
    }
}
