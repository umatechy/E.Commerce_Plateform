<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Domain\Catalog\Models\Product;
use App\Domain\Seo\Models\SeoableType;
use App\Domain\Seo\Models\SeoSetting;
use App\Domain\Seo\Services\SeoResolver;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B13 — SEO fallback hierarchy: entity override -> store default
 * -> generated fallback (Module 16 §7-8, Non-Negotiable).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class SeoResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_falls_back_to_product_name_when_no_override_exists(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $product = Product::factory()->for($store)->create(['name' => 'Blue Shirt']);

        $seo = app(SeoResolver::class)->forProduct($product);

        $this->assertSame('Blue Shirt', $seo->title);
    }

    public function test_store_default_is_used_when_no_entity_override_exists(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        SeoSetting::query()->create(['seoable_type' => SeoableType::Store, 'seoable_id' => null, 'title' => 'Default Store Title']);
        $product = Product::factory()->for($store)->create(['name' => 'Blue Shirt']);

        $seo = app(SeoResolver::class)->forProduct($product);

        $this->assertSame('Default Store Title', $seo->title);
    }

    public function test_entity_specific_override_wins_over_store_default(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        SeoSetting::query()->create(['seoable_type' => SeoableType::Store, 'seoable_id' => null, 'title' => 'Default Store Title']);
        $product = Product::factory()->for($store)->create(['name' => 'Blue Shirt']);
        SeoSetting::query()->create(['seoable_type' => SeoableType::Product, 'seoable_id' => $product->id, 'title' => 'Custom Product Title']);

        $seo = app(SeoResolver::class)->forProduct($product);

        $this->assertSame('Custom Product Title', $seo->title);
    }

    public function test_canonical_url_never_uses_the_request_host_header(): void
    {
        config(['seo.storefront_base_url' => 'https://trusted-platform.example']);
        $store = Store::factory()->create(['slug' => 'my-store']);
        app(TenantContext::class)->resolveToStore($store->id);
        $product = Product::factory()->for($store)->create(['slug' => 'blue-shirt']);

        $seo = app(SeoResolver::class)->forProduct($product);

        // Since Phase B14 the canonical URL is built from the store's own
        // verified primary domain (every store gets one at creation);
        // the config base URL is only a fallback. Either way it is
        // server-derived — never the request's Host header.
        $primary = \App\Domain\Domains\Models\Domain::query()->withoutTenantScope()
            ->where('store_id', $store->id)->where('is_primary', true)->firstOrFail();

        $this->assertSame("https://{$primary->normalized_hostname}/products/blue-shirt", $seo->canonicalUrl);
    }

    public function test_default_robots_directives_are_index_and_follow(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $product = Product::factory()->for($store)->create();

        $seo = app(SeoResolver::class)->forProduct($product);

        $this->assertSame('index, follow', $seo->robotsContent());
    }

    public function test_explicit_noindex_override_is_respected(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $product = Product::factory()->for($store)->create();
        SeoSetting::query()->create(['seoable_type' => SeoableType::Product, 'seoable_id' => $product->id, 'robots_index' => 'noindex']);

        $seo = app(SeoResolver::class)->forProduct($product);

        $this->assertStringContainsString('noindex', $seo->robotsContent());
    }

    public function test_canonical_override_takes_precedence_over_generated_url(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $product = Product::factory()->for($store)->create();
        SeoSetting::query()->create(['seoable_type' => SeoableType::Product, 'seoable_id' => $product->id, 'canonical_override' => 'https://custom.example/special-page']);

        $seo = app(SeoResolver::class)->forProduct($product);

        $this->assertSame('https://custom.example/special-page', $seo->canonicalUrl);
    }
}
