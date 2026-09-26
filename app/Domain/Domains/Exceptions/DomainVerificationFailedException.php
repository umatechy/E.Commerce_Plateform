<?php

declare(strict_types=1);

namespace App\Domain\Domains\Exceptions;

use RuntimeException;

final class DomainVerificationFailedException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }
}
