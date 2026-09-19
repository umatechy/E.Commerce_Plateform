<?php

declare(strict_types=1);

namespace App\Domain\Cart\Exceptions;

use RuntimeException;

final class CartCheckoutNotAllowedException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }
}
