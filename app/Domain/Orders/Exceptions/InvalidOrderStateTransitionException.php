<?php

declare(strict_types=1);

namespace App\Domain\Orders\Exceptions;

use App\Domain\Orders\Models\OrderStatus;
use RuntimeException;

final class InvalidOrderStateTransitionException extends RuntimeException
{
    public function __construct(public readonly OrderStatus $from, public readonly OrderStatus $to)
    {
        parent::__construct("Cannot transition order from [{$from->value}] to [{$to->value}].");
    }
}
