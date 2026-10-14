<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

use App\Domain\Payments\Providers\ProviderCredentials;

/**
 * Phase B48: what a store's own merchant account (PaymentProviderAccount)
 * and Umar Techy's own (PlatformPaymentAccount) have in common — the engine
 * charges through either the same way.
 *
 * Credentials are an encrypted array cast, hidden from every serialisation,
 * and leave the model only as a ProviderCredentials object for one call.
 *
 * @property int $id
 * @property string $public_id
 * @property string $provider
 * @property string $environment
 * @property bool $is_enabled
 * @property array<string, string> $credentials
 * @property ?array<string, string> $credential_hints
 * @property ?\Illuminate\Support\Carbon $verified_at
 * @property ?\Illuminate\Support\Carbon $last_success_at
 * @property ?\Illuminate\Support\Carbon $last_failure_at
 * @property ?string $last_failure_category
 * @property ?int $updated_by
 */
trait MerchantAccount
{
    public function providerCredentials(): ProviderCredentials
    {
        return new ProviderCredentials(array_map('strval', (array) $this->credentials), $this->environment);
    }

    /** @return array<string, mixed> */
    protected function merchantAccountCasts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'credentials' => 'encrypted:array', // Module 12 §72: encrypted at rest
            'credential_hints' => 'array',
            'verified_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public function isLive(): bool
    {
        return $this->environment === ProviderCredentials::PRODUCTION;
    }
}
