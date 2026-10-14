<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Messaging\MessagingAdapter;
use App\Domain\Notifications\Messaging\MessagingEvents;
use App\Domain\Notifications\Messaging\MessagingRegistry;
use App\Domain\Notifications\Messaging\OutgoingMessage;
use App\Domain\Notifications\Messaging\PhoneNumber;
use App\Domain\Notifications\Messaging\SendOutcome;
use App\Domain\Notifications\Models\MessagingAccount;
use App\Domain\Notifications\Models\MessagingTemplate;
use App\Domain\Payments\Providers\CredentialField;
use App\Domain\Payments\Providers\CredentialForm;
use App\Domain\Payments\Providers\ProviderCredentials;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase B50 (Module 21 §31–32, §37, §44, §52, §60): SMS and WhatsApp
 * accounts — a store's own, or Umar Techy's (store_id null) — their test
 * messages, and the WhatsApp templates that carry each update.
 *
 * Same rules as the B48 and B49 accounts (CredentialForm): secrets
 * write-only, masked hints, a new environment needs every value and starts
 * off, audit with field names only. One account per channel.
 */
final class MessagingAccountService
{
    public function __construct(
        private readonly MessagingRegistry $registry,
        private readonly AuditLogger $audit,
    ) {}

    public function account(string $channel, ?int $storeId): ?MessagingAccount
    {
        return MessagingAccount::query()->where('channel', $channel)
            ->when($storeId === null, fn ($q) => $q->whereNull('store_id'), fn ($q) => $q->where('store_id', $storeId))->first();
    }

    /**
     * @param array<string, mixed> $input environment, credentials{…}
     * @throws ValidationException
     */
    public function save(string $provider, array $input, User $actor, ?int $storeId): MessagingAccount
    {
        $adapter = $this->registry->find($provider) ?? throw ValidationException::withMessages(['provider' => 'This messaging provider is not available.']);
        $channel = $adapter->capabilities()->channel;
        $environment = (string) ($input['environment'] ?? '');
        if (! in_array($environment, [ProviderCredentials::SANDBOX, ProviderCredentials::PRODUCTION], true)) {
            throw ValidationException::withMessages(['environment' => 'Choose sandbox (testing) or production (real messages).']);
        }
        if ($environment === ProviderCredentials::PRODUCTION && ! $this->registry->liveAllowed($provider)) {
            throw ValidationException::withMessages(['environment' => $this->registry->isTest($provider)
                ? 'The test messenger has no production.'
                : "Umar Techy has not opened {$adapter->label()} for real messages yet (sender ID, delivery and price checks)."]);
        }

        $account = $this->account($channel, $storeId);
        $same = $account !== null && $account->provider === $provider && $account->environment === $environment;
        $stored = $same ? (array) $account->credentials : [];
        $credentials = CredentialForm::values($adapter->credentialFields(), (array) ($input['credentials'] ?? []), $stored);
        $attributes = [
            'channel' => $channel, 'provider' => $provider, 'environment' => $environment, 'credentials' => $credentials,
            'credential_hints' => CredentialForm::hints($adapter->credentialFields(), $credentials), 'updated_by' => $actor->id,
        ];
        if (! $same) {
            $attributes += ['is_enabled' => false, 'last_success_at' => null, 'last_failure_at' => null, 'last_failure_message' => null];
        }
        if ($account === null) {
            $account = new MessagingAccount($attributes + ['webhook_token' => Str::random(48)]);
            $account->store_id = $storeId; // null: Umar Techy's own (platform context)
            $account->save();
        } else {
            $account->update($attributes);
        }
        $this->audit->record('messaging.account_saved', ['channel' => $channel, 'provider' => $provider, 'environment' => $environment, 'changed_fields' => CredentialForm::changed($credentials, $stored)], $account, $storeId, $actor, platform: $storeId === null);

        return $account->fresh() ?? $account;
    }

    /** @throws ValidationException */
    public function setEnabled(MessagingAccount $account, bool $enabled, User $actor): MessagingAccount
    {
        if ($enabled) {
            $test = $this->registry->isTest($account->provider);
            if ($test && ! MessagingRegistry::simulatorEnabled()) {
                throw ValidationException::withMessages(['enabled' => 'The test messenger is not available here.']);
            }
            if (! $test && ($account->environment !== ProviderCredentials::PRODUCTION || ! $this->registry->liveAllowed($account->provider))) {
                throw ValidationException::withMessages(['enabled' => 'Only a production account that is open for real messages can be switched on. Use "Send a test message" for a sandbox account.']);
            }
        }
        $account->update(['is_enabled' => $enabled, 'updated_by' => $actor->id]);
        $this->audit->record($enabled ? 'messaging.account_enabled' : 'messaging.account_disabled', ['channel' => $account->channel, 'provider' => $account->provider], $account, $account->store_id, $actor, platform: $account->store_id === null);

        return $account;
    }

    public function disconnect(MessagingAccount $account, User $actor): void
    {
        $this->audit->record('messaging.account_disconnected', ['channel' => $account->channel, 'provider' => $account->provider], $account, $account->store_id, $actor, platform: $account->store_id === null);
        $account->delete();
    }

    /**
     * Module 21 §52: one message to a number the person types, to see that
     * the account works — a sandbox account too. WhatsApp sends Meta's own
     * "hello_world" sample template, which every new WhatsApp Business
     * account has.
     *
     * @return array{outcome: string, message: ?string, provider_message_id: ?string}
     * @throws ValidationException
     */
    public function testSend(MessagingAccount $account, string $number, User $actor): array
    {
        $to = PhoneNumber::e164($number) ?? throw ValidationException::withMessages(['number' => 'Enter a mobile number such as 0300 1234567 or +923001234567.']);
        $adapter = $this->registry->get($account->provider);
        $template = $adapter->capabilities()->templates ? ['name' => 'hello_world', 'language' => 'en_US', 'parameters' => []] : null;
        try {
            $outcome = $adapter->send(new OutgoingMessage($to, 'This is a test message from your store on Umar Techy.', $template, null, 'test-'.Str::ulid()), $account->providerCredentials());
        } catch (\Throwable $e) {
            report($e);
            $outcome = new SendOutcome(SendOutcome::UNKNOWN, message: 'The provider call did not finish.');
        }
        $account->forceFill($outcome->outcome === SendOutcome::ACCEPTED ? ['last_success_at' => now()] : ['last_failure_at' => now(), 'last_failure_message' => $outcome->message])->save();
        $this->audit->record('messaging.test_sent', ['channel' => $account->channel, 'to' => PhoneNumber::mask($to), 'outcome' => $outcome->outcome], $account, $account->store_id, $actor, platform: $account->store_id === null);

        return ['outcome' => $outcome->outcome, 'message' => $outcome->message, 'provider_message_id' => $outcome->providerMessageId];
    }

    /**
     * Which approved template carries an update; checked with the provider
     * at once where it can say (Module 21 §37).
     *
     * @throws ValidationException
     */
    public function saveTemplate(string $eventKey, string $name, string $language, User $actor, ?int $storeId): MessagingTemplate
    {
        if (MessagingEvents::find($eventKey) === null || ($storeId !== null && ! in_array($eventKey, MessagingEvents::storeKeys(), true))) {
            throw ValidationException::withMessages(['event_key' => 'Unknown update.']);
        }
        if (preg_match('/^[a-z0-9_]{1,120}$/', $name) !== 1) {
            throw ValidationException::withMessages(['template_name' => 'Template names use small letters, digits and _ only, as in WhatsApp Manager.']);
        }
        if (preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $language) !== 1) {
            throw ValidationException::withMessages(['language_code' => 'A language code such as en, en_US or ur.']);
        }
        $mapping = MessagingTemplate::query()->where('event_key', $eventKey)
            ->when($storeId === null, fn ($q) => $q->whereNull('store_id'), fn ($q) => $q->where('store_id', $storeId))->first()
            ?? new MessagingTemplate(['event_key' => $eventKey]);
        $mapping->store_id = $storeId;
        $mapping->fill(['template_name' => $name, 'language_code' => $language, 'status' => 'submitted', 'checked_at' => null, 'updated_by' => $actor->id])->save();
        $this->audit->record('messaging.template_saved', ['event' => $eventKey, 'template' => $name, 'language' => $language], $mapping, $storeId, $actor, platform: $storeId === null);

        return $this->checkTemplate($mapping);
    }

    public function checkTemplate(MessagingTemplate $mapping): MessagingTemplate
    {
        $account = $this->account('whatsapp', $mapping->store_id);
        $adapter = $account !== null ? $this->registry->find($account->provider) : null;
        if ($account === null || $adapter === null || ! $adapter->capabilities()->templateCheck) {
            return $mapping;
        }
        $status = $adapter->templateStatus($mapping->template_name, $mapping->language_code, $account->providerCredentials());
        $mapping->forceFill(['status' => $status === 'unknown' ? $mapping->status : $status, 'checked_at' => now()])->save();

        return $mapping;
    }

    /** @return array<string, mixed> what settings screens show — never a secret */
    public function describe(MessagingAdapter $adapter, ?MessagingAccount $account, bool $canConfigure): array
    {
        return [
            'provider' => $adapter->key(),
            'label' => $adapter->label(),
            'channel' => $adapter->capabilities()->channel,
            'capabilities' => $adapter->capabilities()->toArray(),
            'credential_fields' => array_map(fn (CredentialField $f) => $f->toArray(), $adapter->credentialFields()),
            'live_allowed' => $this->registry->liveAllowed($adapter->key()),
            'account' => $account === null || $account->provider !== $adapter->key() ? null : [
                'id' => $account->public_id,
                'environment' => $account->environment,
                'is_enabled' => $account->is_enabled,
                'credential_hints' => $account->credential_hints ?? [],
                'last_success_at' => $account->last_success_at?->toIso8601String(),
                'last_failure_at' => $account->last_failure_at?->toIso8601String(),
                'last_failure_message' => $account->last_failure_message,
                // What the provider's dashboard needs: the webhook address and (Meta) its verify token.
                'webhook_url' => $canConfigure ? $account->webhookUrl() : null,
                'verify_token' => $canConfigure && $adapter->key() === 'meta_whatsapp' ? $account->webhook_token : null,
            ],
        ];
    }
}
