<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

/** The code was wrong, already used, or too many wrong codes were tried. */
final class InvalidMfaCodeException extends \RuntimeException
{
    public static function wrong(): self
    {
        return new self('That code is not valid. Check your authenticator app and try again.');
    }

    public static function tooManyAttempts(int $minutes): self
    {
        return new self("Too many wrong codes. Try again in {$minutes} minutes.");
    }
}
