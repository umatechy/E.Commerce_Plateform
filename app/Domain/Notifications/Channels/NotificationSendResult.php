<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Models\DeliveryAttemptResult;

final class NotificationSendResult
{
    public function __construct(
        public readonly DeliveryAttemptResult $result,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureReason = null,
    ) {}
}
