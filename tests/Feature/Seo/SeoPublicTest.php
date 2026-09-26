<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductStatus;
use App\Domain\Catalog\Models\ProductVisibility;
use App\Domain\Seo\Models\ContentPage;
use App\Domain\Seo\Models\ContentPageStatus;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B13 — Public SEO surface: sitemap.xml, robots.txt, resolved
 * SEO for a public storefront route, tenant resolved from the URL
 * path segment only (Module 16 §16, Non-Negotiable). Draft content
 * must never leak into normal public rendering (Module 16 Data
 * Integrity Rule #9).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class SeoPublicTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_is_publicly_reachable_without_authentication(): void
    {
        $store = Store::factory()->create(['slug' => 'my-shop']);
        app(TenantContext::class)->resolveToStore($store->id);

        $response = $this->get('/api/v1/public/seo/my-shop/sitemap.xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function test_robots_txt_is_publicly_reachable_and_disallows_private_paths(): void
    {
        $store = Store::factory()->create(['slug' => 'my-shop']);
        app(TenantContext::class)->resolveToStore($store->id);

        $response = $this->get('/api/v1/public/seo/my-shop/robots.txt');

        $response->assertOk();
        $response->assertSee('Disallow: /api/');
        $response->assertSee('Disallow: /checkout');
    }

    public function test_resolved_product_seo_is_publicly_reachable(): void
    {
        $store = Store::factory()->create(['slug' => 'my-shop']);
        app(TenantContext::class)->resolveToStore($store->id);
        Product::factory()->for($store)->create(['slug' => 'blue-shirt', 'name' => 'Blue Shirt']);

        $response = $this->getJson('/api/v1/public/seo/my-shop/products/blue-shirt');

        $response->assertOk();
        $response->assertJsonPath('data.title', 'Blue Shirt');
    }

    public function test_draft_content_page_is_never_reachable_through_the_public_endpoint(): void
    {
        $store = Store::factory()->create(['slug' => 'my-shop']);
        app(TenantContext::class)->resolveToStore($store->id);
        ContentPage::factory()->for($store)->create(['slug' => 'secret-draft', 'status' => ContentPageStatus::Draft]);

        $response = $this->getJson('/api/v1/public/seo/my-shop/pages/secret-draft');

        $response->assertStatus(404);
    }

    public function test_published_content_page_is_reachable_through_the_public_endpoint(): void
    {
        $store = Store::factory()->create(['slug' => 'my-shop']);
        app(TenantContext::class)->resolveToStore($store->id);
        ContentPage::factory()->for($store)->create(['slug' => 'about-us', 'status' => ContentPageStatus::Published, 'published_at' => now()]);

        $response = $this->getJson('/api/v1/public/seo/my-shop/pages/about-us');

        $response->assertOk();
    }

    public function test_product_structured_data_returns_valid_schema_org_product_type(): void
    {
        $store = Store::factory()->create(['slug' => 'my-shop']);
        app(TenantContext::class)->resolveToStore($store->id);
        Product::factory()->for($store)->create(['slug' => 'blue-shirt', 'price_minor' => 2500, 'currency' => 'USD']);

        $response = $this->getJson('/api/v1/public/seo/my-shop/products/blue-shirt/structured-data');

        $response->assertOk();
        $response->assertJsonPath('@type', 'Product');
        $response->assertJsonPath('offers.price', '25.00');
    }

    public function test_unknown_store_slug_returns_not_found(): void
    {
        $response = $this->get('/api/v1/public/seo/does-not-exist/sitemap.xml');

        $response->assertStatus(404);
    }
}
