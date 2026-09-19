<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

final class DuplicateOpeningStockException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Opening stock has already been set for this inventory record. Use a stock adjustment instead.');
    }
}
