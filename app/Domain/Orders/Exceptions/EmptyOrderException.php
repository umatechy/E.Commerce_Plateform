<?php

declare(strict_types=1);

namespace App\Domain\Orders\Exceptions;

use RuntimeException;

final class EmptyOrderException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('An order must contain at least one item.');
    }
}
