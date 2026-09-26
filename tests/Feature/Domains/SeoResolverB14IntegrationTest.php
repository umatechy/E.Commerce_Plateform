<?php

declare(strict_types=1);

namespace Tests\Feature\Domains;

use App\Domain\Catalog\Models\Product;
use App\Domain\Domains\Models\Domain;
use App\Domain\Domains\Models\DomainStatus;
use App\Domain\Domains\Services\DomainService;
use App\Domain\Seo\Services\SeoResolver;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B14 — B13 SEO canonical URL integration: the one placeholder
 * method B14 replaces (Module 19 §22-26, this milestone's own PRIMARY
 * requirement). Sitemap/robots/Open-Graph all inherit this
 * automatically since they call through SeoResolver.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class SeoResolverB14IntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_url_uses_the_verified_primary_domain_not_the_placeholder(): void
    {
        $store = Store::factory()->create(['slug' => 'my-shop']);
        app(TenantContext::class)->resolveToStore($store->id);
        $platformDomain = Domain::query()->where('store_id', $store->id)->firstOrFail();
        $product = Product::factory()->for($store)->create(['slug' => 'widget']);

        $seo = app(SeoResolver::class)->forProduct($product);

        $this->assertStringStartsWith("https://{$platformDomain->normalized_hostname}", $seo->canonicalUrl);
    }

    public function test_canonical_url_switches_to_a_new_custom_domain_once_it_becomes_primary(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $product = Product::factory()->for($store)->create(['slug' => 'widget']);
        $customDomain = \App\Domain\Domains\Models\Domain::factory()->for($store)->create(['status' => DomainStatus::Verified, 'normalized_hostname' => 'my-real-store.com']);

        app(DomainService::class)->setPrimary($customDomain);
        $seo = app(SeoResolver::class)->forProduct($product);

        $this->assertStringStartsWith('https://my-real-store.com', $seo->canonicalUrl);
    }

    public function test_sitemap_urls_use_the_verified_primary_domain(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $platformDomain = Domain::query()->where('store_id', $store->id)->firstOrFail();
        Product::factory()->for($store)->create(['status' => 'active', 'visibility' => 'public']);

        $urls = app(\App\Domain\Seo\Services\SitemapService::class)->urlsFor($store->fresh());

        $this->assertTrue(collect($urls)->contains(fn ($u) => str_contains($u['loc'], $platformDomain->normalized_hostname)));
    }
}
