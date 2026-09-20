<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Jobs\DeliverNotificationJob;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Notifications\Models\NotificationMessageType;
use App\Domain\Notifications\Models\NotificationStatus;
use App\Domain\Notifications\Models\NotificationSuppression;
use App\Domain\Notifications\Models\RecipientType;
use App\Domain\Orders\Models\Customer;

/**
 * The ONE code path that creates a NotificationMessage (mirrors every
 * other domain service's "service-only writes" pattern). Implements
 * Module 21 §6 "Message Orchestration" up through "Queue Delivery" —
 * actual sending happens in DeliverNotificationJob.
 */
final class NotificationService
{
    public function __construct(private readonly NotificationStateMachine $stateMachine) {}

    /**
     * @param array<string, scalar> $variables
     */
    public function send(
        NotificationMessageType $messageType,
        NotificationChannel $channel,
        RecipientType $recipientType,
        ?int $recipientId,
        string $destination,
        string $subject,
        string $bodyTemplate,
        array $variables,
        string $idempotencyKey,
        ?string $sourceEventType = null,
    ): ?NotificationMessage {
        if ($existing = NotificationMessage::query()->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        $renderer = new NotificationTemplateRenderer();
        $renderedSubject = $renderer->render($subject, $variables, escapeHtml: false);
        $renderedBody = $renderer->render($bodyTemplate, $variables, escapeHtml: $channel === NotificationChannel::Email);

        $message = NotificationMessage::query()->create([
            'message_type' => $messageType,
            'channel' => $channel,
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'destination' => $destination,
            'subject' => $renderedSubject,
            'body' => $renderedBody,
            'status' => NotificationStatus::Created,
            'source_event_type' => $sourceEventType,
            'idempotency_key' => $idempotencyKey,
        ]);

        // Module 21 §9-10/Data Integrity Rule #5-6: ONLY Marketing
        // messages are subject to consent/suppression — mandatory
        // transactional/system/security/administrative communication
        // is never blocked by a marketing preference.
        if ($messageType->requiresMarketingConsent() && ! $this->isEligibleForMarketing($recipientType, $recipientId, $channel, $destination)) {
            $this->transitionTo($message, NotificationStatus::Suppressed);

            return $message->fresh();
        }

        $this->transitionTo($message, NotificationStatus::Queued);
        DeliverNotificationJob::dispatch($message->id);

        return $message->fresh();
    }

    private function isEligibleForMarketing(RecipientType $recipientType, ?int $recipientId, NotificationChannel $channel, string $destination): bool
    {
        if ($recipientType === RecipientType::Customer) {
            $customer = $recipientId !== null ? Customer::query()->find($recipientId) : null;

            if ($customer === null || ! $customer->marketing_email_opt_in) {
                return false; // no known Customer (e.g. a guest) is never eligible for marketing — there is no consent record to check
            }
        }

        $suppressed = NotificationSuppression::query()
            ->where('channel', $channel)
            ->where('destination', $destination)
            ->exists();

        return ! $suppressed;
    }

    private function transitionTo(NotificationMessage $message, NotificationStatus $to): void
    {
        $this->stateMachine->assertCanTransition($message->status, $to);
        $message->update(['status' => $to]);
    }
}
