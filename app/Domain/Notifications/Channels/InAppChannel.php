<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Models\DeliveryAttemptResult;
use App\Domain\Notifications\Models\NotificationMessage;

/**
 * A REAL, complete channel with no external dependency at all —
 * "delivery" for in_app IS the NotificationMessage row's own
 * existence (already tenant/customer-scoped by construction). Nothing
 * is deferred here.
 */
final class InAppChannel implements NotificationChannelContract
{
    public function providerName(): string
    {
        return 'in_app';
    }

    public function send(NotificationMessage $message): NotificationSendResult
    {
        return new NotificationSendResult(DeliveryAttemptResult::Succeeded, providerMessageId: (string) $message->id);
    }
}
