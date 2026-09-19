<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

final class InsufficientStockException extends RuntimeException
{
    public function __construct(public readonly int $inventoryId, public readonly int $requested)
    {
        parent::__construct("Insufficient available stock for inventory #{$inventoryId} (requested {$requested}).");
    }
}
