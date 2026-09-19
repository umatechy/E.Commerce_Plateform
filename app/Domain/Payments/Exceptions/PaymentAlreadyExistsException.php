<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exceptions;

use RuntimeException;

final class PaymentAlreadyExistsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A payment has already been created for this order.');
    }
}
