<?php

declare(strict_types=1);

namespace App\Domain\Settings\Exceptions;

use RuntimeException;

final class SettingRevisionNotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('That setting revision could not be found for this scope.');
    }
}
