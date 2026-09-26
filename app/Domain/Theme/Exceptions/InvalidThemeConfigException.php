<?php

declare(strict_types=1);

namespace App\Domain\Theme\Exceptions;

use RuntimeException;

final class InvalidThemeConfigException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }
}
