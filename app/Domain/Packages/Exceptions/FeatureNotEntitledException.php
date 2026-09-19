<?php

declare(strict_types=1);

namespace App\Domain\Packages\Exceptions;

use RuntimeException;

final class FeatureNotEntitledException extends RuntimeException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct("Your current package does not include [{$key}].");
    }
}
