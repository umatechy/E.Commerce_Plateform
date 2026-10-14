<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Payments\Providers\CredentialField;
use App\Domain\Payments\Providers\CredentialForm;
use App\Domain\Payments\Providers\ProviderCredentials;
use App\Domain\Shipping\Couriers\CourierAdapter;
use App\Domain\Shipping\Couriers\CourierRegistry;
use App\Domain\Shipping\Exceptions\FulfillmentNotAllowedException;
use App\Domain\Shipping\Models\CourierAccount;
use App\Domain\Shipping\Models\Shipment;
use Illuminate\Validation\ValidationException;

/**
 * Phase B49 (Module 13 §44–46, §88): connecting, changing and disconnecting
 * a store's courier accounts — the same rules as B48's merchant accounts
 * (CredentialForm): secrets write-only, masked hints, a new environment
 * needs every value again and starts off, audit with field names only.
 */
final class CourierAccountService
{
    public function __construct(
        private readonly CourierRegistry $couriers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array<string, mixed> $input environment, credentials{…}
     * @throws ValidationException
     */
    public function save(?CourierAccount $account, string $provider, array $input, User $actor, int $storeId): CourierAccount
    {
        $adapter = $this->couriers->find($provider) ?? throw ValidationException::withMessages(['provider' => 'This courier is not available.']);
        $environment = (string) ($input['environment'] ?? '');
        if (! in_array($environment, [ProviderCredentials::SANDBOX, ProviderCredentials::PRODUCTION], true)) {
            throw ValidationException::withMessages(['environment' => 'Choose sandbox (testing) or production (real parcels).']);
        }
        if ($environment === ProviderCredentials::PRODUCTION && ! in_array($provider, CourierRegistry::realCouriers(), true)) {
            throw ValidationException::withMessages(['environment' => $provider === 'test_courier' ? 'The test courier has no production.' : "{$adapter->label()} is not open for real parcels yet."]);
        }

        $sameEnvironment = $account !== null && $account->environment === $environment;
        $stored = $sameEnvironment ? (array) $account->credentials : [];
        $credentials = CredentialForm::values($adapter->credentialFields(), (array) ($input['credentials'] ?? []), $stored);
        $attributes = [
            'provider' => $provider, 'environment' => $environment, 'credentials' => $credentials,
            'credential_hints' => CredentialForm::hints($adapter->credentialFields(), $credentials), 'updated_by' => $actor->id,
        ];
        if (! $sameEnvironment) {
            $attributes += ['is_enabled' => false, 'last_success_at' => null, 'last_failure_at' => null, 'last_failure_message' => null];
        }
        $account = $account === null ? CourierAccount::query()->create($attributes + ['store_id' => $storeId]) : tap($account)->update($attributes);
        $this->audit->record('shipping.courier_account_saved', ['provider' => $provider, 'environment' => $environment, 'changed_fields' => CredentialForm::changed($credentials, $stored)], $account, $storeId, $actor);

        return $account->fresh() ?? $account;
    }

    public function setEnabled(CourierAccount $account, bool $enabled, User $actor): CourierAccount
    {
        $account->update(['is_enabled' => $enabled, 'updated_by' => $actor->id]);
        $this->audit->record($enabled ? 'shipping.courier_account_enabled' : 'shipping.courier_account_disabled', ['provider' => $account->provider], $account, $account->store_id, $actor);

        return $account;
    }

    /** @throws FulfillmentNotAllowedException */
    public function disconnect(CourierAccount $account, User $actor): void
    {
        // Parcels still on their way need the account to be asked about.
        $open = Shipment::query()->where('carrier', $account->provider)->where('booking_status', 'booked')->whereNotNull('next_poll_at')->exists();
        if ($open) {
            throw new FulfillmentNotAllowedException('Some parcels booked through this account are still on their way. Disconnect it once they are delivered or returned.');
        }
        $this->audit->record('shipping.courier_account_disconnected', ['provider' => $account->provider], $account, $account->store_id, $actor);
        $account->delete();
    }

    /** @return array<string, mixed> never a secret, never a full value */
    public function describe(CourierAdapter $adapter, ?CourierAccount $account): array
    {
        return [
            'provider' => $adapter->key(),
            'label' => $adapter->label(),
            'capabilities' => $adapter->capabilities()->toArray(),
            'credential_fields' => array_map(fn (CredentialField $f) => $f->toArray(), $adapter->credentialFields()),
            'account' => $account === null ? null : [
                'id' => $account->public_id,
                'environment' => $account->environment,
                'is_enabled' => $account->is_enabled,
                'credential_hints' => $account->credential_hints ?? [],
                'last_success_at' => $account->last_success_at?->toIso8601String(),
                'last_failure_at' => $account->last_failure_at?->toIso8601String(),
                'last_failure_message' => $account->last_failure_message,
            ],
        ];
    }
}
