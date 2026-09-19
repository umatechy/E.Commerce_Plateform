<?php

declare(strict_types=1);

namespace App\Domain\Packages\Exceptions;

use RuntimeException;

final class UsageLimitExceededException extends RuntimeException
{
    public function __construct(public readonly string $key, public readonly int $limit)
    {
        parent::__construct("Usage limit exceeded for [{$key}] (limit: {$limit}).");
    }
}
