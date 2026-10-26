<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Services;

use RuntimeException;

/** More points were asked for than the customer has (the balance is never negative). */
final class InsufficientPoints extends RuntimeException
{
    public function __construct(public readonly int $requested, public readonly int $balance)
    {
        parent::__construct('There are not that many points.');
    }
}
