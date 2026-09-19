<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exceptions;

use App\Domain\Payments\Models\PaymentStatus;
use RuntimeException;

final class InvalidPaymentStateTransitionException extends RuntimeException
{
    public function __construct(public readonly PaymentStatus $from, public readonly PaymentStatus $to)
    {
        parent::__construct("Cannot transition payment from [{$from->value}] to [{$to->value}].");
    }
}
