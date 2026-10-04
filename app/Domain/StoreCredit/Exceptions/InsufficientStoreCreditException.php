<?php

declare(strict_types=1);

namespace App\Domain\StoreCredit\Exceptions;

use RuntimeException;

/** More store credit was asked for than the customer has (Module 09 §52: the balance is never negative). */
final class InsufficientStoreCreditException extends RuntimeException
{
    public function __construct(public readonly int $requestedMinor, public readonly int $balanceMinor)
    {
        parent::__construct('There is not that much store credit.');
    }
}
