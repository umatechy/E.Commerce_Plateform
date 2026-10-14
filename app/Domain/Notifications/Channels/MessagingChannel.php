<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Messaging\MessagingRegistry;
use App\Domain\Notifications\Messaging\OutgoingMessage;
use App\Domain\Notifications\Messaging\PhoneNumber;
use App\Domain\Notifications\Messaging\SendOutcome;
use App\Domain\Notifications\Models\DeliveryAttemptResult;
use App\Domain\Notifications\Models\MessagingAccount;
use App\Domain\Notifications\Models\MessagingTemplate;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Notifications\Models\NotificationStatus;
use App\Domain\Notifications\Models\NotificationSuppression;
use App\Domain\Settings\Services\ConfigService;

/**
 * Phase B50 (gap G10; Module 21 §18–19, §29, §31–33, §46): SMS and WhatsApp
 * as notification channels, replacing B11's "unconfigured" stubs. The
 * notification engine stays provider-agnostic (owner decision 3): this
 * channel finds the account, checks what must be checked just before
 * sending, and hands the message to the provider's adapter.
 *
 * Checked here, in this order: an account that is switched on and may send
 * (test accounts never reach real people; Twilio production waits for the
 * platform's go-ahead), a readable number, no STOP from that number, the
 * store's daily limit, and — WhatsApp — an approved template for the event.
 *
 * What cannot change by retrying (bad number, opted out, no template,
 * credentials refused, limit) fails at once. A provider that did not
 * answer is not asked again: the customer could get the message twice
 * (Module 21 §33).
 */
final class MessagingChannel implements NotificationChannelContract
{
    private string $provider = 'none';

    public function __construct(private readonly NotificationChannel $channel) {}

    public function providerName(): string
    {
        return $this->provider;
    }

    public function send(NotificationMessage $message): NotificationSendResult
    {
        $registry = app(MessagingRegistry::class);
        $account = MessagingAccount::query()->where('channel', $this->channel->value)
            ->when($message->store_id === null, fn ($q) => $q->whereNull('store_id'), fn ($q) => $q->where('store_id', $message->store_id))
            ->first();
        $adapter = $account !== null ? $registry->find($account->provider) : null;
        if ($account === null || $adapter === null || ! $account->is_enabled || (! $registry->isTest($account->provider) && ($account->environment !== 'production' || ! $registry->liveAllowed($account->provider)))) {
            return $this->fail('channel_not_configured', "No {$this->channel->value} account is switched on.");
        }
        $this->provider = $adapter->key();

        $to = PhoneNumber::e164($message->destination);
        if ($to === null) {
            return $this->fail('invalid_number', 'The phone number cannot be read.');
        }
        if ($message->store_id !== null && NotificationSuppression::query()->where('store_id', $message->store_id)->where('channel', $this->channel)->where('destination', $to)->exists()) {
            return $this->fail('opted_out', 'The customer asked not to receive these messages (STOP).');
        }
        if ($message->store_id !== null && $this->sentToday($message) >= (int) app(ConfigService::class)->get('messaging.daily_limit')) {
            return $this->fail('daily_limit_reached', 'The store\'s daily limit for this channel is reached.');
        }

        $template = null;
        if ($adapter->capabilities()->templates) {
            // Platform messages are critical alerts (one template); a store's carry their update's key.
            $eventKey = $message->store_id === null ? 'critical_alert' : (string) $message->source_event_type;
            $mapping = MessagingTemplate::query()->where('event_key', $eventKey)
                ->when($message->store_id === null, fn ($q) => $q->whereNull('store_id'), fn ($q) => $q->where('store_id', $message->store_id))
                ->where('status', 'approved')->first();
            if ($mapping === null) {
                return $this->fail('template_missing', 'No approved WhatsApp template is set for this update.');
            }
            $template = ['name' => $mapping->template_name, 'language' => $mapping->language_code, 'parameters' => array_values(array_map('strval', (array) $message->provider_parameters))];
        }

        $outgoing = new OutgoingMessage(
            to: $to,
            text: trim(html_entity_decode(strip_tags($message->deliverableBody()), ENT_QUOTES)),
            template: $template,
            statusCallbackUrl: $adapter->capabilities()->freeText ? $account->webhookUrl() : null,
            reference: $message->public_id,
        );
        try {
            $outcome = $adapter->send($outgoing, $account->providerCredentials());
        } catch (\Throwable $e) {
            report($e);
            $outcome = new SendOutcome(SendOutcome::UNKNOWN, code: 'no_answer', message: 'The provider call did not finish.');
        }
        $account->forceFill($outcome->outcome === SendOutcome::ACCEPTED
            ? ['last_success_at' => now()]
            : ['last_failure_at' => now(), 'last_failure_message' => $outcome->message])->save();

        return match ($outcome->outcome) {
            SendOutcome::ACCEPTED => new NotificationSendResult(DeliveryAttemptResult::Succeeded, providerMessageId: $outcome->providerMessageId),
            SendOutcome::UNKNOWN => $this->fail('no_answer', 'No answer from the provider; not sent again so the customer does not get it twice.'),
            default => new NotificationSendResult(DeliveryAttemptResult::Failed, failureCode: $outcome->code, failureReason: $outcome->message, permanent: $outcome->permanent),
        };
    }

    private function fail(string $code, string $reason): NotificationSendResult
    {
        return new NotificationSendResult(DeliveryAttemptResult::Failed, failureCode: $code, failureReason: $reason, permanent: true);
    }

    private function sentToday(NotificationMessage $message): int
    {
        return NotificationMessage::query()->where('store_id', $message->store_id)->where('channel', $this->channel)
            ->whereIn('status', [NotificationStatus::Sent, NotificationStatus::Delivered])
            ->where('sent_at', '>=', now()->startOfDay())->count();
    }
}
