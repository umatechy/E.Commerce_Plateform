<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Models\DeliveryAttemptResult;
use App\Domain\Notifications\Models\NotificationMessage;

/**
 * Contract-compliant stub for SMS/WhatsApp/Push (Module 21 §18-20) —
 * see docs/development/b11-inspection-findings.md "Channel Scope" for
 * why no live provider exists to call. Every message/delivery-attempt
 * record around this is fully real; only the actual provider call is
 * absent. Never claims success.
 */
final class UnconfiguredChannel implements NotificationChannelContract
{
    public function __construct(private readonly string $name) {}

    public function providerName(): string
    {
        return $this->name;
    }

    public function send(NotificationMessage $message): NotificationSendResult
    {
        return new NotificationSendResult(
            DeliveryAttemptResult::Failed,
            failureCode: 'channel_not_configured',
            failureReason: "No {$this->name} provider is configured for this platform yet.",
        );
    }
}
