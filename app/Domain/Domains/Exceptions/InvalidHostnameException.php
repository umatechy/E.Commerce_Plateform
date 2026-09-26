<?php

declare(strict_types=1);

namespace App\Domain\Domains\Exceptions;

use RuntimeException;

final class InvalidHostnameException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }
}
