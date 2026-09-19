<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exceptions;

use RuntimeException;

final class RefundExceedsRefundableBalanceException extends RuntimeException
{
    public function __construct(public readonly int $requested, public readonly int $refundable)
    {
        parent::__construct("Refund of {$requested} exceeds refundable balance of {$refundable}.");
    }
}
