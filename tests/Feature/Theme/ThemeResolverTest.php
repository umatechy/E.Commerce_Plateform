<?php

declare(strict_types=1);

namespace Tests\Feature\Theme;

use App\Domain\Theme\Models\StoreTheme;
use App\Domain\Theme\Services\ThemeResolver;
use App\Domain\Theme\Services\ThemeService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B15 — ThemeResolver: only PUBLISHED config is ever publicly
 * resolvable, never the draft (Module 17 §10, Non-Negotiable).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class ThemeResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_published_returns_the_published_config(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        $resolved = app(ThemeResolver::class)->resolvePublished($store->fresh());

        $this->assertNotNull($resolved);
        $this->assertArrayHasKey('config', $resolved);
    }

    public function test_resolve_published_never_leaks_the_unpublished_draft(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $storeTheme = StoreTheme::query()->where('store_id', $store->id)->firstOrFail();
        // A valid, distinctive draft value that was never published.
        app(ThemeService::class)->updateDraft($storeTheme, ['tokens' => ['primary' => '#5EC4E7']], null);

        $resolved = app(ThemeResolver::class)->resolvePublished($store->fresh());

        // The RESOLVED value is the last PUBLISHED one, never draft_config.
        $this->assertSame('#5EC4E7', $storeTheme->fresh()->draft_config['tokens']['primary']);
        $this->assertNotSame('#5EC4E7', $resolved['config']['tokens']['primary'] ?? null);
    }

    public function test_resolve_draft_returns_the_stores_own_draft(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $storeTheme = StoreTheme::query()->where('store_id', $store->id)->firstOrFail();
        app(ThemeService::class)->updateDraft($storeTheme, ['tokens' => ['primary' => '#123123']], null);

        $draft = app(ThemeResolver::class)->resolveDraft($storeTheme->fresh());

        $this->assertSame('#123123', $draft['config']['tokens']['primary']);
    }
}
