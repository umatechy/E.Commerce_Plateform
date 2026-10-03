<?php

declare(strict_types=1);

namespace App\Domain\Customers\Exceptions;

/** A customer action the rules do not allow (e.g. blocking an erased customer). Answered as 409 or 422 with the message. */
final class CustomerActionRefusedException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 409)
    {
        parent::__construct($message);
    }
}
