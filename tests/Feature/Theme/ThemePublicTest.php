<?php

declare(strict_types=1);

namespace Tests\Feature\Theme;

use App\Domain\Theme\Models\StoreTheme;
use App\Domain\Theme\Services\ThemeService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B15 — Public resolved-theme endpoint: no authentication
 * required, tenant resolved from the URL path, published-only, never
 * cross-tenant (Module 17 §4/§10, Non-Negotiable).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class ThemePublicTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_theme_is_publicly_reachable_without_authentication(): void
    {
        $store = Store::factory()->create(['slug' => 'my-shop']);

        $response = $this->getJson('/api/v1/public/theme/my-shop');

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['config', 'published_at']]);
    }

    public function test_unknown_store_slug_returns_not_found(): void
    {
        $response = $this->getJson('/api/v1/public/theme/does-not-exist');

        $response->assertStatus(404);
    }

    public function test_public_endpoint_never_returns_store_bs_configuration_for_store_a(): void
    {
        $storeA = Store::factory()->create(['slug' => 'store-a']);
        $storeB = Store::factory()->create(['slug' => 'store-b']);
        app(TenantContext::class)->resolveToStore($storeB->id);
        $storeThemeB = StoreTheme::query()->where('store_id', $storeB->id)->firstOrFail();
        app(ThemeService::class)->updateDraft($storeThemeB, ['tokens' => ['primary' => '#B00B00']], null);
        app(ThemeService::class)->publish($storeThemeB->fresh(), null);

        $response = $this->getJson('/api/v1/public/theme/store-a');

        $response->assertOk();
        $response->assertJsonMissing(['primary' => '#B00B00']);
    }

    public function test_public_endpoint_reflects_a_newly_published_configuration(): void
    {
        $store = Store::factory()->create(['slug' => 'my-shop']);
        app(TenantContext::class)->resolveToStore($store->id);
        $storeTheme = StoreTheme::query()->where('store_id', $store->id)->firstOrFail();
        app(ThemeService::class)->updateDraft($storeTheme, ['tokens' => ['primary' => '#00FF00']], null);
        app(ThemeService::class)->publish($storeTheme->fresh(), null);

        $response = $this->getJson('/api/v1/public/theme/my-shop');

        $response->assertOk();
        $response->assertJsonPath('data.config.tokens.primary', '#00FF00');
    }
}
