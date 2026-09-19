<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Exceptions;

use RuntimeException;

final class DestinationNotServiceableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This destination is not currently serviceable.');
    }
}
