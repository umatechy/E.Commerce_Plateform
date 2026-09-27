<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Services;

use App\Domain\DeveloperPlatform\Exceptions\ApplicationNotActiveException;
use App\Domain\DeveloperPlatform\Exceptions\InvalidApiScopeException;
use App\Domain\DeveloperPlatform\Models\ApiKey;
use App\Domain\DeveloperPlatform\Models\ApiKeyStatus;
use App\Domain\DeveloperPlatform\Models\ApiScope;
use App\Domain\DeveloperPlatform\Models\ApplicationStatus;
use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use App\Domain\Events\Support\RecordsOutboxEvents;
use Illuminate\Support\Str;

/**
 * Module 31 §9-12/§44 "API Key Architecture / Lifecycle / Rotation /
 * Credential Security" — Non-Negotiable: the plaintext secret is
 * generated here, returned to the caller EXACTLY ONCE (at issue/
 * rotate time), and never stored — only its SHA-256 hash persists.
 * This is the ONLY code path that creates, verifies, or revokes an
 * ApiKey.
 */
final class ApiKeyService
{
    private const PREFIX_LENGTH = 8;
    private const SECRET_LENGTH = 40;

    public function __construct(private readonly RecordsOutboxEvents $outbox) {}

    /**
     * @param list<string> $scopes
     * @return array{key: ApiKey, plaintext: string}
     *
     * @throws ApplicationNotActiveException
     * @throws InvalidApiScopeException
     */
    public function issue(DeveloperApplication $application, array $scopes, ?\DateTimeInterface $expiresAt = null): array
    {
        if ($application->status !== ApplicationStatus::Active) {
            throw new ApplicationNotActiveException();
        }

        $validatedScopes = $this->validateScopes($scopes);

        [$prefix, $secret, $hash] = $this->generateCredential();

        $key = ApiKey::query()->create([
            'public_id' => (string) Str::ulid(),
            'developer_application_id' => $application->id,
            'store_id' => $application->store_id,
            'key_prefix' => $prefix,
            'key_hash' => $hash,
            'scopes' => $validatedScopes,
            'status' => ApiKeyStatus::Active,
            'expires_at' => $expiresAt,
        ]);

        $this->outbox->recordEvent(
            eventType: 'developer.api_key.created',
            payload: ['api_key_id' => $key->id, 'application_id' => $application->id, 'store_id' => $application->store_id, 'scopes' => $validatedScopes],
            idempotencyKey: "api_key:{$key->id}:created",
        );

        return ['key' => $key, 'plaintext' => "{$prefix}.{$secret}"];
    }

    public function revoke(ApiKey $key): ApiKey
    {
        $key->update(['status' => ApiKeyStatus::Revoked, 'revoked_at' => now()]);

        $this->outbox->recordEvent(
            eventType: 'developer.api_key.revoked',
            payload: ['api_key_id' => $key->id, 'store_id' => $key->store_id],
            idempotencyKey: "api_key:{$key->id}:revoked:".now()->timestamp,
        );

        return $key->fresh();
    }

    /**
     * Module 31 §12 "Key Rotation" — no invented grace/overlap period
     * (none is specified): the OLD key is revoked in the SAME
     * operation the new one is issued, immediate replacement only.
     *
     * @return array{key: ApiKey, plaintext: string}
     */
    public function rotate(ApiKey $oldKey): array
    {
        $issued = $this->issue($oldKey->application, $oldKey->scopes, $oldKey->expires_at);
        $this->revoke($oldKey);

        return $issued;
    }

    /**
     * Module 31 §44-45 "Credential Security / Enumeration" — a lookup
     * by the non-secret prefix, then a constant-time hash comparison;
     * NEVER a database query against secret material itself. Returns
     * null uniformly for "prefix not found," "hash mismatch," "not
     * usable" — never a distinguishing error, so a failed
     * authentication attempt cannot be used to enumerate which
     * failure mode occurred.
     */
    public function verify(string $plaintext): ?ApiKey
    {
        if (! str_contains($plaintext, '.')) {
            return null;
        }

        [$prefix, $secret] = explode('.', $plaintext, 2);

        $key = ApiKey::query()->withoutTenantScope()->where('key_prefix', $prefix)->first();

        if ($key === null || ! hash_equals($key->key_hash, hash('sha256', $secret)) || ! $key->isUsable()) {
            return null;
        }

        $key->update(['last_used_at' => now()]);

        return $key;
    }

    /**
     * @param list<string> $scopes
     * @return list<string>
     *
     * @throws InvalidApiScopeException
     */
    private function validateScopes(array $scopes): array
    {
        if ($scopes === []) {
            throw new InvalidApiScopeException('(none provided)');
        }

        foreach ($scopes as $scope) {
            if (ApiScope::tryFrom($scope) === null) {
                throw new InvalidApiScopeException($scope);
            }
        }

        return array_values(array_unique($scopes));
    }

    /** @return array{0: string, 1: string, 2: string} [prefix, plaintext secret, sha256 hash] */
    private function generateCredential(): array
    {
        $prefix = 'utk_'.Str::lower(Str::random(self::PREFIX_LENGTH));
        $secret = Str::random(self::SECRET_LENGTH);

        return [$prefix, $secret, hash('sha256', $secret)];
    }
}
