<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\Models\NotificationStatus;
use RuntimeException;

final class InvalidNotificationStateTransitionException extends RuntimeException
{
    public function __construct(public readonly NotificationStatus $from, public readonly NotificationStatus $to)
    {
        parent::__construct("Cannot transition notification from [{$from->value}] to [{$to->value}].");
    }
}
