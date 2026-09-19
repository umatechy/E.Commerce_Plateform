<?php

declare(strict_types=1);

namespace App\Domain\Orders\Exceptions;

use App\Domain\Orders\Models\OrderStatus;
use RuntimeException;

final class OrderCancellationNotAllowedException extends RuntimeException
{
    public function __construct(public readonly OrderStatus $currentStatus)
    {
        parent::__construct("Orders in status [{$currentStatus->value}] cannot be cancelled directly — use the return workflow instead.");
    }
}
