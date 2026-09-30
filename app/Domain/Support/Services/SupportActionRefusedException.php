<?php

declare(strict_types=1);

namespace App\Domain\Support\Services;

/** A well-formed support action the ticket's state does not allow (answered 409). */
final class SupportActionRefusedException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
