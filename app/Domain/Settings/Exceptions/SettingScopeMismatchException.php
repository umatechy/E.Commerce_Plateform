<?php

declare(strict_types=1);

namespace App\Domain\Settings\Exceptions;

use RuntimeException;

/** Module 33 §8 "A tenant setting must never become a platform-global setting accidentally." */
final class SettingScopeMismatchException extends RuntimeException
{
    public function __construct(string $key, string $expectedScope)
    {
        parent::__construct("\"{$key}\" is a {$expectedScope}-scope setting and cannot be written through this endpoint.");
    }
}
