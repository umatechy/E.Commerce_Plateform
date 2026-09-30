<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Exceptions;

use RuntimeException;

/** A customer with orders still in progress cannot be erased yet. */
final class CustomerErasureBlockedException extends RuntimeException
{
    public function __construct(public readonly int $openOrders)
    {
        parent::__construct("This customer has {$openOrders} order(s) still in progress; erase their data once those are completed or cancelled.");
    }
}
