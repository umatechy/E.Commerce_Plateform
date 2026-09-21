<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Exceptions;

use RuntimeException;

final class InvalidDateRangeException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }
}
