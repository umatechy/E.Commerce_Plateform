<?php

declare(strict_types=1);

namespace App\Domain\Settings\Exceptions;

use RuntimeException;

final class InvalidSettingValueException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }
}
