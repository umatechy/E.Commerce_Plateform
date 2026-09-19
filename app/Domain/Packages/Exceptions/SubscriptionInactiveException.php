<?php

declare(strict_types=1);

namespace App\Domain\Packages\Exceptions;

use App\Domain\Packages\Models\SubscriptionStatus;
use RuntimeException;

final class SubscriptionInactiveException extends RuntimeException
{
    public function __construct(public readonly SubscriptionStatus $status)
    {
        parent::__construct("Your store's subscription is currently [{$status->value}] and does not grant feature access.");
    }
}
