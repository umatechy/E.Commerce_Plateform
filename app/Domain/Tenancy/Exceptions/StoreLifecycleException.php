<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

/**
 * Phase B51: a store lifecycle change that is well-formed but not allowed
 * now (reopening after the retention period, deleting a store on legal
 * hold …). Answered as 422 with its machine-readable code.
 */
final class StoreLifecycleException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
