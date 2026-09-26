<?php

declare(strict_types=1);

namespace Tests\Feature\Theme;

use App\Domain\Theme\Exceptions\StoreThemePublicationNotFoundException;
use App\Domain\Theme\Models\StoreTheme;
use App\Domain\Theme\Models\Theme;
use App\Domain\Theme\Services\ThemeService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B15 — Theme lifecycle: auto-creation (self-healing, no seeder
 * dependency), draft/publish separation, rollback (Module 17 §16/§19-
 * 20, Non-Negotiable).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class ThemeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_store_automatically_gets_an_auto_published_default_theme(): void
    {
        // Regression test for the exact critical fix in
        // docs/development/b15-inspection-findings.md ("Second Bug
        // Found") — this must succeed WITHOUT ThemeSeeder ever having
        // been called (RefreshDatabase-based tests never auto-seed).
        $this->assertSame(0, Theme::query()->count()); // confirms no seeder ran before this test

        $store = Store::factory()->create();

        $storeTheme = StoreTheme::query()->where('store_id', $store->id)->first();
        $this->assertNotNull($storeTheme);
        $this->assertNotNull($storeTheme->published_config); // auto-published, not left as draft-only
        $this->assertTrue($storeTheme->isPublished());
    }

    public function test_updating_the_draft_never_touches_the_published_config(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $storeTheme = StoreTheme::query()->where('store_id', $store->id)->firstOrFail();
        $originalPublished = $storeTheme->published_config;

        app(ThemeService::class)->updateDraft($storeTheme, ['tokens' => ['primary' => '#123456']], null);

        $this->assertSame($originalPublished, $storeTheme->fresh()->published_config);
        $this->assertSame('#123456', $storeTheme->fresh()->draft_config['tokens']['primary']);
    }

    public function test_publishing_copies_the_draft_into_published_and_records_a_ledger_entry(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $storeTheme = StoreTheme::query()->where('store_id', $store->id)->firstOrFail();
        app(ThemeService::class)->updateDraft($storeTheme, ['tokens' => ['primary' => '#ABCDEF']], null);

        $published = app(ThemeService::class)->publish($storeTheme->fresh(), null);

        $this->assertSame('#ABCDEF', $published->published_config['tokens']['primary']);
        $this->assertSame(1, $storeTheme->publications()->count());
    }

    public function test_rollback_restores_an_earlier_published_snapshot(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $storeTheme = StoreTheme::query()->where('store_id', $store->id)->firstOrFail();
        $firstPublication = $storeTheme->publications()->firstOrFail(); // the auto-published default itself

        app(ThemeService::class)->updateDraft($storeTheme->fresh(), ['tokens' => ['primary' => '#000001']], null);
        app(ThemeService::class)->publish($storeTheme->fresh(), null);

        $rolledBack = app(ThemeService::class)->rollbackTo($storeTheme->fresh(), $firstPublication->id, null);

        $this->assertSame($firstPublication->config, $rolledBack->published_config);
    }

    public function test_rolling_back_to_a_nonexistent_publication_id_is_rejected(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $storeTheme = StoreTheme::query()->where('store_id', $store->id)->firstOrFail();

        $this->expectException(StoreThemePublicationNotFoundException::class);
        app(ThemeService::class)->rollbackTo($storeTheme, 999999, null);
    }

    public function test_theme_reset_never_touches_unrelated_business_data(): void
    {
        // Module 17 §37/§73 "Theme Reset Safety" — publishing/rolling
        // back a theme must never delete/modify products, orders,
        // customers, domains, or SEO data.
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $product = \App\Domain\Catalog\Models\Product::factory()->for($store)->create();
        $storeTheme = StoreTheme::query()->where('store_id', $store->id)->firstOrFail();

        app(ThemeService::class)->publish($storeTheme, null);

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('domains', ['store_id' => $store->id]);
    }
}
