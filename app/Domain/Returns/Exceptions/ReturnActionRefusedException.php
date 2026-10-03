<?php

declare(strict_types=1);

namespace App\Domain\Returns\Exceptions;

use RuntimeException;

/** A return action the rules do not allow right now, with words for the person and a code for the screen. */
final class ReturnActionRefusedException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 409)
    {
        parent::__construct($message);
    }
}
