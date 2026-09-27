<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Services;

use App\Domain\DeveloperPlatform\Jobs\DispatchWebhookJob;
use App\Domain\DeveloperPlatform\Models\WebhookSubscription;
use App\Domain\DeveloperPlatform\Models\WebhookSubscriptionStatus;

/**
 * Module 31 §36 "Webhooks" — the SECOND real outbox consumer, added
 * BESIDE (never replacing) B11's NotificationEventRouter, both called
 * from the same unchanged ConsumeOutboxEventJob::handle() (see
 * docs/development/b18-inspection-findings.md). No second event bus.
 */
final class WebhookEventRouter
{
    public function route(string $eventType, int $storeId, array $payload, string $sourceIdempotencyKey): void
    {
        $subscriptions = WebhookSubscription::query()
            ->where('store_id', $storeId)
            ->where('status', WebhookSubscriptionStatus::Active)
            ->get()
            ->filter(fn (WebhookSubscription $s) => $s->isSubscribedTo($eventType));

        foreach ($subscriptions as $subscription) {
            DispatchWebhookJob::dispatch(
                webhookSubscriptionId: $subscription->id,
                eventType: $eventType,
                payload: $payload,
                idempotencyKey: "{$sourceIdempotencyKey}:{$subscription->id}",
            );
        }
    }
}
