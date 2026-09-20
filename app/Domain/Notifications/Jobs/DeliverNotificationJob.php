<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Jobs;

use App\Domain\Notifications\Channels\NotificationChannelResolver;
use App\Domain\Notifications\Models\DeliveryAttemptResult;
use App\Domain\Notifications\Models\NotificationDeliveryAttempt;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Notifications\Models\NotificationStatus;
use App\Domain\Notifications\Services\NotificationStateMachine;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Module 21 §23/§29-30 "Queue-Based Delivery / Retry Policy /
 * Idempotency." Tenant context is resolved from the NotificationMessage
 * row's OWN store_id (loaded by this job's own database lookup) —
 * never trusted from an arbitrary job payload field; only the internal
 * `notificationMessageId` integer is serialized (same pattern as
 * Phase B10's ProcessCampaignExecutionJob).
 */
final class DeliverNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $notificationMessageId) {}

    /** Module 21 §29 "exponential backoff... maximum attempts." */
    public function backoff(): array
    {
        return [10, 30, 60, 300, 900];
    }

    public function handle(TenantContext $context, NotificationChannelResolver $channels, NotificationStateMachine $stateMachine): void
    {
        $message = NotificationMessage::query()->withoutTenantScope()->findOrFail($this->notificationMessageId);
        $context->resolveToStore($message->store_id);
        $message = NotificationMessage::query()->findOrFail($this->notificationMessageId);

        // Idempotent-replay / terminal-state guard (Module 21 §30) —
        // mirrors Phase B10's identical fix: a retried/duplicate job
        // dispatch after the message already reached a terminal state
        // is a safe no-op, never a crash or a duplicate send.
        if ($message->status->isTerminal()) {
            return;
        }

        $this->transitionTo($message, $stateMachine, NotificationStatus::Processing);

        $gateway = $channels->resolve($message->channel);
        $result = $gateway->send($message);
        $attemptNumber = $message->attempts()->count() + 1;

        NotificationDeliveryAttempt::query()->create([
            'notification_message_id' => $message->id,
            'attempt_number' => $attemptNumber,
            'provider' => $gateway->providerName(),
            'provider_message_id' => $result->providerMessageId,
            'result' => $result->result,
            'failure_code' => $result->failureCode,
            'failure_reason' => $result->failureReason,
        ]);

        if ($result->result === DeliveryAttemptResult::Succeeded) {
            $this->transitionTo($message, $stateMachine, NotificationStatus::Sent, ['sent_at' => now()]);
            // In-app has no separate "delivered by a provider" signal
            // — the message existing IS delivery, so it goes straight
            // to Delivered; every other channel waits for a real
            // provider confirmation (§35, deferred — see inspection
            // findings) and stays at Sent.
            if ($message->channel === \App\Domain\Notifications\Models\NotificationChannel::InApp) {
                $this->transitionTo($message, $stateMachine, NotificationStatus::Delivered);
            }

            return;
        }

        // Module 21 §29: "Do not retry permanent failures indefinitely."
        // A channel_not_configured failure (stub SMS/WhatsApp/Push) is
        // a PERMANENT failure — retrying it can never succeed, so it
        // goes straight to Failed rather than RetryPending.
        if ($result->failureCode === 'channel_not_configured') {
            $this->transitionTo($message, $stateMachine, NotificationStatus::Failed);

            return;
        }

        if ($attemptNumber >= $this->tries) {
            $this->transitionTo($message, $stateMachine, NotificationStatus::Failed);

            return;
        }

        $this->transitionTo($message, $stateMachine, NotificationStatus::RetryPending);
        $this->release($this->backoff()[$attemptNumber - 1] ?? 900);
    }

    private function transitionTo(NotificationMessage $message, NotificationStateMachine $stateMachine, NotificationStatus $to, array $extra = []): void
    {
        $stateMachine->assertCanTransition($message->status, $to);
        $message->update(['status' => $to, ...$extra]);
        $message->refresh();
    }

    public function failed(\Throwable $exception): void
    {
        NotificationMessage::query()->withoutTenantScope()
            ->whereKey($this->notificationMessageId)
            ->update(['status' => NotificationStatus::Failed->value]);
    }
}
