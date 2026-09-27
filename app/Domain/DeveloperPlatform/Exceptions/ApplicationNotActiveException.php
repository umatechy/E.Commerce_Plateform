<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Exceptions;

use RuntimeException;

final class ApplicationNotActiveException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This application is not active and cannot have new API keys issued.');
    }
}
