<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Domain\Settings\Exceptions\SettingRevisionNotFoundException;
use App\Domain\Settings\Models\SettingRevision;
use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B17 — Revision history + rollback, cross-tenant rollback
 * structurally blocked via an EXPLICIT store_id filter (SettingRevision
 * itself carries no automatic tenant scope, since one ledger spans both
 * scopes — see the migration's own docblock) (Module 33 §31-32, Non-
 * Negotiable).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class SettingRevisionRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_write_creates_a_revision(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        app(ConfigService::class)->set('store.timezone', 'Asia/Karachi', SettingScope::Store, null, 'initial setup');
        app(ConfigService::class)->set('store.timezone', 'Europe/London', SettingScope::Store, null, 'changed my mind');

        $history = app(ConfigService::class)->history('store.timezone');

        $this->assertCount(2, $history);
    }

    public function test_rollback_restores_an_earlier_value(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        app(ConfigService::class)->set('store.timezone', 'Asia/Karachi', SettingScope::Store, null);
        $firstRevision = SettingRevision::query()->where('key', 'store.timezone')->first();
        app(ConfigService::class)->set('store.timezone', 'Europe/London', SettingScope::Store, null);

        app(ConfigService::class)->rollbackTo($firstRevision->id, null);

        $this->assertSame('Asia/Karachi', app(ConfigService::class)->get('store.timezone'));
    }

    public function test_rollback_itself_is_recorded_as_a_new_revision(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        app(ConfigService::class)->set('store.timezone', 'Asia/Karachi', SettingScope::Store, null);
        $firstRevision = SettingRevision::query()->where('key', 'store.timezone')->first();

        app(ConfigService::class)->rollbackTo($firstRevision->id, null);

        $this->assertSame(2, SettingRevision::query()->where('key', 'store.timezone')->count());
    }

    public function test_store_a_cannot_roll_back_using_store_bs_revision_id(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($storeB->id);
        app(ConfigService::class)->set('store.timezone', 'Asia/Karachi', SettingScope::Store, null);
        $storeBRevision = SettingRevision::query()->where('key', 'store.timezone')->first();

        app(TenantContext::class)->resolveToStore($storeA->id);

        $this->expectException(SettingRevisionNotFoundException::class);
        app(ConfigService::class)->rollbackTo($storeBRevision->id, null);
    }

    public function test_history_for_a_store_setting_never_includes_another_stores_revisions(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($storeA->id);
        app(ConfigService::class)->set('store.timezone', 'Asia/Karachi', SettingScope::Store, null);
        app(TenantContext::class)->resolveToStore($storeB->id);
        app(ConfigService::class)->set('store.timezone', 'Europe/London', SettingScope::Store, null);

        $historyForB = app(ConfigService::class)->history('store.timezone');

        $this->assertCount(1, $historyForB);
        $this->assertSame('Europe/London', $historyForB->first()->value[0]);
    }
}
