<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Messaging\MessagingRegistry;
use App\Domain\Notifications\Messaging\PhoneNumber;
use App\Domain\Notifications\Messaging\ProviderEvent;
use App\Domain\Notifications\Models\MessagingAccount;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationDeliveryAttempt;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Notifications\Models\NotificationStatus;
use App\Domain\Notifications\Models\NotificationSuppression;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase B50 (Module 21 §35–36, §10, §48): what SMS and WhatsApp providers
 * tell us — delivery reports and customers' replies.
 *
 * - The webhook address names one account (an unguessable token); the
 *   provider's signature is checked with that account's own secret.
 * - Each report is applied once (provider event key, unique per account).
 * - A report only changes a message of the account's own store, found by
 *   the provider's message id from our delivery attempt — never one named
 *   in the payload by store or customer.
 * - A reply of STOP (or a similar word) stops that channel for that number
 *   in that store; START allows it again.
 */
final class MessagingWebhookService
{
    private const STOP_WORDS = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'بند'];

    private const START_WORDS = ['START', 'UNSTOP', 'YES'];

    public function __construct(
        private readonly MessagingRegistry $registry,
        private readonly NotificationStateMachine $states,
        private readonly TenantContext $context,
    ) {}

    public function account(string $token): ?MessagingAccount
    {
        return strlen($token) === 48 ? MessagingAccount::query()->withoutTenantScope()->where('webhook_token', $token)->first() : null;
    }

    /** Meta's subscription check: echo the challenge when the verify token is this account's. */
    public function challenge(MessagingAccount $account, Request $request): ?string
    {
        return $request->query('hub_mode', $request->query('hub.mode')) === 'subscribe'
            && hash_equals($account->webhook_token, (string) $request->query('hub_verify_token', $request->query('hub.verify_token', '')))
            ? (string) $request->query('hub_challenge', $request->query('hub.challenge', ''))
            : null;
    }

    /** @return array{applied: int, ignored: int}|null null when the signature does not hold */
    public function handle(MessagingAccount $account, Request $request): ?array
    {
        $adapter = $this->registry->find($account->provider);
        if ($adapter === null || ! $adapter->verifyWebhook($request, $account->providerCredentials(), $account->webhookUrl())) {
            return null;
        }
        $account->store_id === null ? $this->context->resolveToPlatform() : $this->context->resolveToStore($account->store_id);

        $applied = 0;
        $ignored = 0;
        foreach ($adapter->parseWebhook($request) as $event) {
            try {
                DB::table('messaging_webhook_events')->insert(['messaging_account_id' => $account->id, 'event_key' => mb_substr($event->key, 0, 80), 'kind' => $event->kind, 'created_at' => now()]);
            } catch (QueryException) {
                $ignored++; // the same report again

                continue;
            }
            $done = $event->kind === ProviderEvent::STATUS ? $this->status($account, $event) : $this->inbound($account, $event);
            $done ? $applied++ : $ignored++;
        }

        return ['applied' => $applied, 'ignored' => $ignored];
    }

    private function status(MessagingAccount $account, ProviderEvent $event): bool
    {
        $attempt = NotificationDeliveryAttempt::query()->withoutGlobalScopes()->where('provider', $account->provider)
            ->where('provider_message_id', $event->providerMessageId)->latest('id')->first();
        $message = $attempt !== null ? NotificationMessage::query()->withoutGlobalScopes()->find($attempt->notification_message_id) : null;
        if ($attempt === null || $message === null || $message->store_id !== $account->store_id) {
            return false; // not ours (another store's account never touches it)
        }

        return DB::transaction(function () use ($message, $attempt, $event) {
            $message = NotificationMessage::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($message->id);
            switch ($event->status) {
                case 'delivered':
                case 'read':
                    if ($message->status === NotificationStatus::Sent) {
                        $this->states->assertCanTransition($message->status, NotificationStatus::Delivered);
                        $message->status = NotificationStatus::Delivered;
                    }
                    if ($event->status === 'read' && $message->read_at === null) {
                        $message->read_at = now();
                    }
                    $message->save();

                    return true;
                case 'failed':
                    if ($message->status === NotificationStatus::Sent) {
                        $message->update(['status' => NotificationStatus::Failed]);
                        $attempt->update(['failure_code' => $event->errorCode ? 'provider_'.$event->errorCode : 'undelivered', 'failure_reason' => $event->errorText ?? 'The provider could not deliver the message.']);
                    }

                    return true;
                default:
                    return false; // "sent": already known
            }
        });
    }

    private function inbound(MessagingAccount $account, ProviderEvent $event): bool
    {
        $from = PhoneNumber::e164($event->from);
        $word = mb_strtoupper(trim((string) $event->text));
        if ($from === null || $account->store_id === null) {
            return false;
        }
        $channel = NotificationChannel::from($account->channel);
        if (in_array($word, self::STOP_WORDS, true)) {
            NotificationSuppression::query()->firstOrCreate(['store_id' => $account->store_id, 'channel' => $channel, 'destination' => $from], ['reason' => 'unsubscribed']);
            if ($channel === NotificationChannel::WhatsApp) {
                // The customer's own "no" also clears their WhatsApp yes (Module 10 stays the record of consent).
                $forms = [$from, ltrim($from, '+'), ...(str_starts_with($from, '+92') ? ['0'.substr($from, 3)] : [])];
                Customer::query()->where('store_id', $account->store_id)->whereIn('phone', $forms)->get()
                    ->each(fn (Customer $c) => $c->forceFill(['whatsapp_opt_in' => false, 'whatsapp_opt_in_at' => null])->save());
            }

            return true;
        }
        if (in_array($word, self::START_WORDS, true)) {
            return NotificationSuppression::query()->where('store_id', $account->store_id)->where('channel', $channel)->where('destination', $from)->delete() > 0;
        }

        return false; // other replies are not read here (no inbox in this phase)
    }
}
