<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Payments\Exceptions\OnlinePaymentRefusedException;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Models\PaymentProviderAccount;
use App\Domain\Payments\Models\PlatformPaymentAccount;
use App\Domain\Payments\Providers\CredentialField;
use App\Domain\Payments\Providers\CredentialForm;
use App\Domain\Payments\Providers\ProviderAdapter;
use App\Domain\Payments\Providers\ProviderCredentials;
use App\Domain\Payments\Providers\ProviderRegistry;
use Illuminate\Validation\ValidationException;

/**
 * Phase B48 — Module 12 §31–34, §94; Module 30 §27: connecting, changing and
 * disconnecting merchant accounts, for a store (its own money) and for Umar
 * Techy (subscription invoices).
 *
 * - Secrets are write-only: a blank secret keeps the stored one; the API
 *   only ever learns "set". Non-secret values come back masked.
 * - Moving to another environment needs every value again: sandbox and
 *   production credentials are different, and nothing is carried over.
 * - Production needs the platform's go-ahead for the provider
 *   (`payments.live_providers`) — the provider's integration must have been
 *   checked in its sandbox first.
 * - Every change is audited with the field names, never the values.
 */
final class ProviderAccountService
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array<string, mixed> $input environment, credentials{…}, min_amount_minor, max_amount_minor
     * @throws ValidationException
     */
    public function save(PaymentProviderAccount|PlatformPaymentAccount|null $account, string $provider, array $input, User $actor, ?int $storeId): PaymentProviderAccount|PlatformPaymentAccount
    {
        $adapter = $this->providers->find($provider) ?? throw ValidationException::withMessages(['provider' => 'This payment provider is not available.']);
        $environment = (string) ($input['environment'] ?? '');
        if (! in_array($environment, [ProviderCredentials::SANDBOX, ProviderCredentials::PRODUCTION], true)) {
            throw ValidationException::withMessages(['environment' => 'Choose sandbox (testing) or production (real money).']);
        }
        if ($environment === ProviderCredentials::PRODUCTION && ! $this->providers->liveAllowed($provider)) {
            throw ValidationException::withMessages(['environment' => $provider === 'simulator'
                ? 'The test wallet has no production.'
                : 'Umar Techy has not opened '.$adapter->label().' for real payments yet. Connect a sandbox account to test it.']);
        }

        $sameEnvironment = $account !== null && $account->environment === $environment;
        $credentials = CredentialForm::values($adapter->credentialFields(), (array) ($input['credentials'] ?? []), $sameEnvironment ? (array) $account->credentials : []);
        $limits = $storeId !== null ? $this->limits($input) : [];

        $changed = CredentialForm::changed($credentials, $sameEnvironment ? (array) $account->credentials : []);

        $attributes = [
            'provider' => $provider, 'environment' => $environment, 'credentials' => $credentials,
            'credential_hints' => CredentialForm::hints($adapter->credentialFields(), $credentials), 'updated_by' => $actor->id, ...$limits,
        ];
        if (! $sameEnvironment) {
            // A new environment starts unproven and off.
            $attributes += ['is_enabled' => false, 'verified_at' => null, 'last_success_at' => null, 'last_failure_at' => null, 'last_failure_category' => null];
        }

        if ($account === null) {
            $account = $storeId !== null
                ? PaymentProviderAccount::query()->create($attributes + ['store_id' => $storeId])
                : PlatformPaymentAccount::query()->create($attributes);
        } else {
            $account->update($attributes);
        }

        $this->audit->record('payments.provider_account_saved', [
            'provider' => $provider, 'environment' => $environment, 'changed_fields' => $changed,
            'min_amount_minor' => $limits['min_amount_minor'] ?? null, 'max_amount_minor' => $limits['max_amount_minor'] ?? null,
        ], $account, $storeId, $actor, platform: $storeId === null);

        return $account->fresh() ?? $account;
    }

    /** @throws OnlinePaymentRefusedException */
    public function setEnabled(PaymentProviderAccount|PlatformPaymentAccount $account, bool $enabled, User $actor, ?int $storeId): PaymentProviderAccount|PlatformPaymentAccount
    {
        if ($enabled) {
            if ($account->provider !== 'simulator' && ! $account->isLive()) {
                throw new OnlinePaymentRefusedException('sandbox_not_offered', 'A sandbox account is for test payments only. Connect the production account to take real payments.');
            }
            if ($account->isLive() && ! $this->providers->liveAllowed($account->provider)) {
                throw new OnlinePaymentRefusedException('not_live_yet', 'This provider is not open for real payments yet.');
            }
            if ($account->provider === 'simulator' && ! ProviderRegistry::simulatorEnabled()) {
                throw new OnlinePaymentRefusedException('not_available', 'The test wallet is not available here.');
            }
        }
        $account->update(['is_enabled' => $enabled, 'updated_by' => $actor->id]);
        $this->audit->record($enabled ? 'payments.provider_account_enabled' : 'payments.provider_account_disabled', ['provider' => $account->provider, 'environment' => $account->environment], $account, $storeId, $actor, platform: $storeId === null);

        return $account;
    }

    /** @throws OnlinePaymentRefusedException */
    public function disconnect(PaymentProviderAccount|PlatformPaymentAccount $account, User $actor, ?int $storeId): void
    {
        $column = $account instanceof PlatformPaymentAccount ? 'platform_account_id' : 'provider_account_id';
        $waiting = PaymentAttempt::query()->withoutTenantScope()->where($column, $account->id)->whereIn('status', [PaymentAttempt::PENDING, PaymentAttempt::UNKNOWN])->exists();
        if ($waiting) {
            // Those attempts still need the account to be asked about.
            throw new OnlinePaymentRefusedException('attempts_waiting', 'Some payments through this account are still waiting for an answer. Try again once they are settled.', 409);
        }
        $this->audit->record('payments.provider_account_disconnected', ['provider' => $account->provider, 'environment' => $account->environment], $account, $storeId, $actor, platform: $storeId === null);
        $account->delete();
    }

    /** @return array<string, mixed> what the settings screens show — never a secret, never a full value */
    public function describe(ProviderAdapter $adapter, PaymentProviderAccount|PlatformPaymentAccount|null $account): array
    {
        return [
            'provider' => $adapter->key(),
            'label' => $adapter->label(),
            'capabilities' => $adapter->capabilities()->toArray(),
            'credential_fields' => array_map(fn (CredentialField $f) => $f->toArray(), $adapter->credentialFields()),
            'live_allowed' => $this->providers->liveAllowed($adapter->key()),
            'account' => $account === null ? null : [
                'id' => $account->public_id,
                'environment' => $account->environment,
                'is_enabled' => $account->is_enabled,
                'credential_hints' => $account->credential_hints ?? [],
                'min_amount_minor' => $account instanceof PaymentProviderAccount ? $account->min_amount_minor : null,
                'max_amount_minor' => $account instanceof PaymentProviderAccount ? $account->max_amount_minor : null,
                'verified_at' => $account->verified_at?->toIso8601String(),
                'last_success_at' => $account->last_success_at?->toIso8601String(),
                'last_failure_at' => $account->last_failure_at?->toIso8601String(),
                'last_failure_category' => $account->last_failure_category,
                'updated_at' => $account->updated_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{min_amount_minor: ?int, max_amount_minor: ?int}
     * @throws ValidationException
     */
    private function limits(array $input): array
    {
        $min = isset($input['min_amount_minor']) && $input['min_amount_minor'] !== '' ? $input['min_amount_minor'] : null;
        $max = isset($input['max_amount_minor']) && $input['max_amount_minor'] !== '' ? $input['max_amount_minor'] : null;
        foreach (['min_amount_minor' => $min, 'max_amount_minor' => $max] as $key => $value) {
            if ($value !== null && (! is_int($value) || $value < 1)) {
                throw ValidationException::withMessages([$key => 'Enter an amount above zero, or leave it empty.']);
            }
        }
        if ($min !== null && $max !== null && $min > $max) {
            throw ValidationException::withMessages(['max_amount_minor' => 'The maximum must be at least the minimum.']);
        }

        return ['min_amount_minor' => $min, 'max_amount_minor' => $max];
    }
}
