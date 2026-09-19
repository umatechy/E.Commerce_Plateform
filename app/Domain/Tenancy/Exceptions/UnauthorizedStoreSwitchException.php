<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

final class UnauthorizedStoreSwitchException extends RuntimeException
{
    public function __construct(public readonly int $requestedStoreId)
    {
        parent::__construct('You do not have an active membership in the requested store.');
    }
}
