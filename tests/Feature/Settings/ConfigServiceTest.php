<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Domain\Settings\Exceptions\InvalidSettingValueException;
use App\Domain\Settings\Exceptions\SettingScopeMismatchException;
use App\Domain\Settings\Exceptions\UnknownSettingKeyException;
use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B17 — ConfigService: the one resolution point, deterministic
 * hierarchy, scope enforcement, cache invalidation (Module 33 §4/§9,
 * Non-Negotiable).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class ConfigServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_unset_setting_resolves_to_its_code_defined_default(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        $value = app(ConfigService::class)->get('store.timezone');

        $this->assertSame('UTC', $value);
    }

    public function test_store_override_takes_precedence_over_the_default(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        app(ConfigService::class)->set('store.timezone', 'Asia/Karachi', SettingScope::Store, null);

        $value = app(ConfigService::class)->get('store.timezone');

        $this->assertSame('Asia/Karachi', $value);
    }

    public function test_store_default_locale_falls_back_to_the_platform_default_locale_when_unset(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        app(ConfigService::class)->set('platform.default_locale', 'fr', SettingScope::Platform, null);

        $value = app(ConfigService::class)->get('store.default_locale');

        $this->assertSame('fr', $value);
    }

    public function test_setting_a_platform_key_with_store_scope_is_rejected(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        $this->expectException(SettingScopeMismatchException::class);
        app(ConfigService::class)->set('platform.maintenance_mode', true, SettingScope::Store, null);
    }

    public function test_unknown_key_is_rejected_before_touching_the_database(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        $this->expectException(UnknownSettingKeyException::class);
        app(ConfigService::class)->set('made.up.key', 'x', SettingScope::Store, null);
    }

    public function test_store_default_currency_must_be_a_platform_supported_currency(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        app(ConfigService::class)->set('platform.supported_currencies', ['USD', 'EUR'], SettingScope::Platform, null);

        $this->expectException(InvalidSettingValueException::class);
        app(ConfigService::class)->set('store.default_currency', 'PKR', SettingScope::Store, null);
    }

    public function test_store_a_and_store_b_have_independent_settings(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($storeA->id);
        app(ConfigService::class)->set('store.timezone', 'Asia/Karachi', SettingScope::Store, null);

        app(TenantContext::class)->resolveToStore($storeB->id);
        $valueForB = app(ConfigService::class)->get('store.timezone');

        $this->assertSame('UTC', $valueForB); // never inherits Store A's override
    }

    public function test_cache_is_invalidated_immediately_after_a_write(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        app(ConfigService::class)->get('store.timezone'); // warms the cache with the default

        app(ConfigService::class)->set('store.timezone', 'Europe/London', SettingScope::Store, null);
        $value = app(ConfigService::class)->get('store.timezone');

        $this->assertSame('Europe/London', $value); // never a stale cached default
    }
}
