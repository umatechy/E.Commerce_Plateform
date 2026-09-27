<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Jobs;

use App\Domain\DeveloperPlatform\Models\WebhookDeliveryAttempt;
use App\Domain\DeveloperPlatform\Models\WebhookSubscription;
use App\Domain\DeveloperPlatform\Models\WebhookSubscriptionStatus;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * Module 31 §36-38 "Webhooks / Signing / Replay Protection". Job
 * payload carries only IDs/plain event data — never a secret (§55
 * "Configuration Access in Jobs" — never serialize a secret into a
 * queue payload; the signing secret is re-read from the row inside
 * handle(), never passed in via the constructor).
 */
final class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly int $webhookSubscriptionId,
        public readonly string $eventType,
        public readonly array $payload,
        public readonly string $idempotencyKey,
    ) {}

    public function backoff(): array
    {
        return [10, 30, 60, 300, 900];
    }

    public function handle(TenantContext $context): void
    {
        $subscription = WebhookSubscription::query()->withoutTenantScope()->find($this->webhookSubscriptionId);

        if ($subscription === null || $subscription->status !== WebhookSubscriptionStatus::Active) {
            return; // subscription was disabled/deleted since this job was queued — nothing to deliver
        }

        $context->resolveToStore($subscription->store_id);

        // Module 31 §38 "Replay Protection" — a prior SUCCESSFUL
        // delivery for this exact idempotency key means this event was
        // already delivered; never re-send (this job may itself be
        // retried by the queue after a successful HTTP call whose
        // acknowledgement was lost).
        $alreadyDelivered = WebhookDeliveryAttempt::query()
            ->where('webhook_subscription_id', $subscription->id)
            ->where('idempotency_key', $this->idempotencyKey)
            ->where('result', 'succeeded')
            ->exists();

        if ($alreadyDelivered) {
            return;
        }

        $attemptNumber = $this->attempts();
        $body = json_encode(['event' => $this->eventType, 'data' => $this->payload, 'idempotency_key' => $this->idempotencyKey]);
        $signature = hash_hmac('sha256', $body, $subscription->signing_secret);

        try {
            $response = Http::timeout(10)
                ->withHeaders(['X-Umartechy-Signature' => $signature, 'X-Umartechy-Event' => $this->eventType])
                ->withBody($body, 'application/json')
                ->post($subscription->url);

            WebhookDeliveryAttempt::query()->create([
                'store_id' => $subscription->store_id, 'webhook_subscription_id' => $subscription->id,
                'event_type' => $this->eventType, 'idempotency_key' => $this->idempotencyKey, 'attempt_number' => $attemptNumber,
                'result' => $response->successful() ? 'succeeded' : 'failed', 'response_status' => $response->status(),
            ]);

            if (! $response->successful()) {
                $this->release($this->backoff()[$attemptNumber - 1] ?? 900);
            }
        } catch (\Throwable $e) {
            WebhookDeliveryAttempt::query()->create([
                'store_id' => $subscription->store_id, 'webhook_subscription_id' => $subscription->id,
                'event_type' => $this->eventType, 'idempotency_key' => $this->idempotencyKey, 'attempt_number' => $attemptNumber,
                'result' => 'failed', 'response_status' => null,
            ]);

            throw $e; // lets Laravel's own retry/backoff mechanism handle it
        }
    }
}
