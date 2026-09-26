<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductStatus;
use App\Domain\Catalog\Models\ProductVisibility;
use App\Domain\Seo\Models\ContentPage;
use App\Domain\Seo\Models\ContentPageStatus;
use App\Domain\Seo\Models\SeoableType;
use App\Domain\Seo\Models\SeoSetting;
use App\Domain\Seo\Services\SitemapService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B13 — Sitemap only includes publicly indexable content, never
 * cross-tenant URLs (Module 16 §17-18, Non-Negotiable).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class SitemapServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_public_product_is_included(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        Product::factory()->for($store)->create(['status' => ProductStatus::Active, 'visibility' => ProductVisibility::Public, 'slug' => 'visible-product']);

        $urls = app(SitemapService::class)->urlsFor($store->fresh());

        $this->assertTrue(collect($urls)->contains(fn ($u) => str_contains($u['loc'], 'visible-product')));
    }

    public function test_draft_product_is_excluded(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        Product::factory()->for($store)->create(['status' => ProductStatus::Draft, 'visibility' => ProductVisibility::Public, 'slug' => 'draft-product']);

        $urls = app(SitemapService::class)->urlsFor($store->fresh());

        $this->assertFalse(collect($urls)->contains(fn ($u) => str_contains($u['loc'], 'draft-product')));
    }

    public function test_noindex_product_is_excluded(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $product = Product::factory()->for($store)->create(['status' => ProductStatus::Active, 'visibility' => ProductVisibility::Public, 'slug' => 'noindex-product']);
        SeoSetting::query()->create(['seoable_type' => SeoableType::Product, 'seoable_id' => $product->id, 'robots_index' => 'noindex']);

        $urls = app(SitemapService::class)->urlsFor($store->fresh());

        $this->assertFalse(collect($urls)->contains(fn ($u) => str_contains($u['loc'], 'noindex-product')));
    }

    public function test_draft_content_page_is_excluded(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        ContentPage::factory()->for($store)->create(['slug' => 'draft-page', 'status' => ContentPageStatus::Draft]);

        $urls = app(SitemapService::class)->urlsFor($store->fresh());

        $this->assertFalse(collect($urls)->contains(fn ($u) => str_contains($u['loc'], 'draft-page')));
    }

    public function test_published_content_page_is_included(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        ContentPage::factory()->for($store)->create(['slug' => 'about-us', 'status' => ContentPageStatus::Published, 'published_at' => now()]);

        $urls = app(SitemapService::class)->urlsFor($store->fresh());

        $this->assertTrue(collect($urls)->contains(fn ($u) => str_contains($u['loc'], 'about-us')));
    }

    public function test_store_a_sitemap_never_includes_store_b_urls(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($storeB->id);
        Product::factory()->for($storeB)->create(['status' => ProductStatus::Active, 'visibility' => ProductVisibility::Public, 'slug' => 'store-b-product']);

        app(TenantContext::class)->resolveToStore($storeA->id);
        $urls = app(SitemapService::class)->urlsFor($storeA->fresh());

        $this->assertFalse(collect($urls)->contains(fn ($u) => str_contains($u['loc'], 'store-b-product')));
    }

    public function test_valid_xml_is_produced(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        Product::factory()->for($store)->create(['status' => ProductStatus::Active, 'visibility' => ProductVisibility::Public]);

        $xml = app(SitemapService::class)->toXml(app(SitemapService::class)->urlsFor($store->fresh()));

        $this->assertStringContainsString('<urlset', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }
}
