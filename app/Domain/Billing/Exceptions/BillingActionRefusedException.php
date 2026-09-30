<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

/**
 * A billing action that is well-formed but not allowed in the current
 * state (paying a void invoice, cancelling twice, ...). Controllers
 * answer it with its status and machine-readable code.
 */
final class BillingActionRefusedException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }
}
