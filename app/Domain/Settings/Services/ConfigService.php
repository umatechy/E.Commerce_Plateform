<?php

declare(strict_types=1);

namespace App\Domain\Settings\Services;

use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Settings\Exceptions\InvalidSettingValueException;
use App\Domain\Settings\Exceptions\SettingScopeMismatchException;
use App\Domain\Settings\Exceptions\UnknownSettingKeyException;
use App\Domain\Settings\Models\PlatformSetting;
use App\Domain\Settings\Models\SettingRevision;
use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Models\SettingType;
use App\Domain\Settings\Models\StoreSetting;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Module 33 §9/§22 "Configuration Resolution Service" — the ONE place
 * a setting is ever read or written. Mirrors every other domain's
 * "service-only writes" pattern exactly. Resolution hierarchy (§4,
 * collapsed to this platform's actual 2-scope model — see inspection
 * findings): Store override -> platform-key fallback (where defined)
 * -> code-defined default. Deterministic, never depends on row order.
 */
final class ConfigService
{
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly SettingValidator $validator,
        private readonly TenantContext $context,
        private readonly RecordsOutboxEvents $outbox,
    ) {}

    /**
     * @throws UnknownSettingKeyException
     */
    public function get(string $key): mixed
    {
        $definition = $this->requireDefinition($key);

        $cacheKey = $this->cacheKey($definition);

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($definition) {
            $raw = $definition->scope === SettingScope::Platform
                ? PlatformSetting::query()->where('key', $definition->key)->first()?->value
                : StoreSetting::query()->where('key', $definition->key)->first()?->value;

            if ($raw !== null) {
                return $definition->type === SettingType::Secret ? Crypt::decryptString($raw[0]) : $raw[0];
            }

            if ($definition->fallsBackToPlatformKey !== null) {
                return $this->get($definition->fallsBackToPlatformKey);
            }

            return $definition->default;
        });
    }

    /**
     * @throws UnknownSettingKeyException
     * @throws SettingScopeMismatchException
     * @throws InvalidSettingValueException
     */
    public function set(string $key, mixed $value, SettingScope $expectedScope, ?int $actorUserId, ?string $reason = null): void
    {
        $definition = $this->requireDefinition($key);

        if ($definition->scope !== $expectedScope) {
            throw new SettingScopeMismatchException($key, $definition->scope->value);
        }

        $validated = $this->validator->validate($definition, $value);

        // Module 33 §9/cross-field rule — the ONE such rule this
        // milestone has: a store's chosen currency must actually be
        // one the platform supports.
        if ($key === 'store.default_currency' && ! in_array($validated, $this->get('platform.supported_currencies'), true)) {
            throw new InvalidSettingValueException('Value must be one of the platform\'s supported currencies.');
        }

        // Phase B38: the default language must be offered, and stays offered.
        if ($key === 'store.default_locale' && ! in_array($validated, (array) $this->get('store.languages'), true)) {
            throw new InvalidSettingValueException('Offer this language on the storefront first (Storefront languages).');
        }
        if ($key === 'store.languages' && ! in_array($this->get('store.default_locale') ?? Locales::DEFAULT, $validated, true)) {
            throw new InvalidSettingValueException('The default language must stay in the list. Change the default language first.');
        }

        $storedValue = $definition->type === SettingType::Secret ? Crypt::encryptString($validated) : $validated;

        $storeId = $definition->scope === SettingScope::Platform ? null : $this->context->storeId();

        // Value, revision and outbox event commit together (ADR-004).
        DB::transaction(function () use ($definition, $key, $storedValue, $storeId, $actorUserId, $reason) {
            if ($storeId === null) {
                PlatformSetting::query()->updateOrCreate(['key' => $key], ['value' => [$storedValue], 'updated_by_user_id' => $actorUserId]);
            } else {
                StoreSetting::query()->updateOrCreate(['store_id' => $storeId, 'key' => $key], ['value' => [$storedValue], 'updated_by_user_id' => $actorUserId]);
            }

            $revision = SettingRevision::query()->create([
                'scope' => $definition->scope, 'store_id' => $storeId, 'key' => $key,
                'value' => [$storedValue], 'changed_by_user_id' => $actorUserId, 'reason' => $reason,
            ]);

            // Module 33 §29/§60 — Non-Negotiable: never place a secret value
            // into an event payload. A platform setting is a platform-scope
            // event (null store). The revision id makes the key unique per
            // change — the previous timestamp suffix collided whenever one
            // setting changed twice within a second.
            $this->outbox->recordEventFor(
                $storeId,
                eventType: 'setting.changed',
                payload: ['key' => $key, 'scope' => $definition->scope->value, 'store_id' => $storeId, 'sensitive' => $definition->type === SettingType::Secret],
                idempotencyKey: "setting:revision:{$revision->id}",
            );
        });

        // After commit: a concurrent reader must not re-cache the old value
        // between the forget and the commit.
        Cache::forget($this->cacheKey($definition));
    }

    /**
     * Module 33 §31 "Change History" — ALWAYS explicitly filtered by
     * the current store for a store-scope key (SettingRevision itself
     * carries no BelongsToTenant global scope, since one ledger
     * legitimately spans both scopes — see the migration's own
     * docblock) — never an implicit/automatic filter, to guarantee no
     * cross-tenant history leak is even possible to write accidentally.
     *
     * @throws UnknownSettingKeyException
     */
    public function history(string $key): \Illuminate\Support\Collection
    {
        $definition = $this->requireDefinition($key);

        $query = SettingRevision::query()->where('key', $key)->orderByDesc('created_at');

        if ($definition->scope === SettingScope::Store) {
            $query->where('store_id', $this->context->storeId());
        } else {
            $query->whereNull('store_id');
        }

        return $query->get();
    }

    /**
     * Module 33 §32 "Rollback" — re-applies an earlier revision's value
     * through the SAME validated write path (set()), never a direct
     * database write. Idempotent: rolling back to the current value is
     * a safe no-op write (still recorded as its own revision, per
     * Module 33's own explicit "audit the rollback" requirement).
     *
     * @throws UnknownSettingKeyException
     * @throws \App\Domain\Settings\Exceptions\SettingRevisionNotFoundException
     */
    public function rollbackTo(int $revisionId, ?int $actorUserId, ?SettingScope $onlyScope = null): void
    {
        $revision = SettingRevision::query()->find($revisionId);

        if ($revision === null) {
            throw new \App\Domain\Settings\Exceptions\SettingRevisionNotFoundException();
        }

        $definition = $this->requireDefinition($revision->key);

        // Phase B32 security fix: the store endpoint passes Store, the
        // platform endpoint Platform. Before, a store's staff could roll back
        // a PLATFORM setting through the store endpoint by its revision id.
        if ($onlyScope !== null && $definition->scope !== $onlyScope) {
            throw new \App\Domain\Settings\Exceptions\SettingRevisionNotFoundException();
        }

        // Module 33 §32: rollback must not cross tenant boundaries —
        // a store-scope revision can only ever be rolled back while
        // the CURRENT tenant context matches the revision's own store.
        if ($definition->scope === SettingScope::Store && $revision->store_id !== $this->context->storeId()) {
            throw new \App\Domain\Settings\Exceptions\SettingRevisionNotFoundException();
        }

        $rawValue = $revision->value[0];
        $value = $definition->type === SettingType::Secret ? Crypt::decryptString($rawValue) : $rawValue;

        $this->set($revision->key, $value, $definition->scope, $actorUserId, reason: "rollback_to_revision_{$revisionId}");
    }

    /**
     * @throws UnknownSettingKeyException
     */
    private function requireDefinition(string $key): SettingDefinition
    {
        return SettingRegistry::find($key) ?? throw new UnknownSettingKeyException($key);
    }

    private function cacheKey(SettingDefinition $definition): string
    {
        return $definition->scope === SettingScope::Platform
            ? "settings:platform:{$definition->key}"
            : "settings:store:{$this->context->storeId()}:{$definition->key}";
    }
}
