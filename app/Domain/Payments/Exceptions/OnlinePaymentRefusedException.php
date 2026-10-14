<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exceptions;

/** Phase B48: an online payment action that is not allowed now; `code` is stable for the UI. */
final class OnlinePaymentRefusedException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
