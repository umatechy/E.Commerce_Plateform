<?php

declare(strict_types=1);

namespace App\Domain\Settings\Exceptions;

use RuntimeException;

/** Module 33 Non-Negotiable §12 "No Arbitrary Key/Value Admin" — thrown for any key not present in SettingRegistry. */
final class UnknownSettingKeyException extends RuntimeException
{
    public function __construct(string $key)
    {
        parent::__construct("\"{$key}\" is not a recognized setting.");
    }
}
