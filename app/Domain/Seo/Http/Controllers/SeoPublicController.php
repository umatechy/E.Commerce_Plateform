<?php

declare(strict_types=1);

namespace App\Domain\Seo\Http\Controllers;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Seo\Http\Resources\ResolvedSeoResource;
use App\Domain\Seo\Models\ContentPage;
use App\Domain\Seo\Models\ContentPageStatus;
use App\Domain\Seo\Services\RobotsService;
use App\Domain\Seo\Services\SeoResolver;
use App\Domain\Seo\Services\SitemapService;
use App\Domain\Seo\Services\StructuredDataService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Http\Response;

/**
 * Public, unauthenticated SEO surface — sitemap.xml, robots.txt, and
 * resolved SEO/structured-data for a public storefront route. Tenant
 * is ALWAYS resolved from the explicit `{storeSlug}` PATH segment,
 * NEVER the request's Host header (Module 16 §16 "Host Header
 * Security", Non-Negotiable) — a search crawler has no custom headers
 * to send, so path-based resolution is the only safe, unambiguous
 * mechanism here.
 */
final class SeoPublicController
{
    public function sitemap(string $storeSlug, SitemapService $sitemaps): Response
    {
        $store = $this->resolveStore($storeSlug);
        $urls = $sitemaps->urlsFor($store);

        return response($sitemaps->toXml($urls), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots(string $storeSlug, RobotsService $robots): Response
    {
        $store = $this->resolveStore($storeSlug);

        return response($robots->generate($store), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function product(string $storeSlug, string $productSlug, SeoResolver $resolver): ResolvedSeoResource
    {
        $this->resolveStore($storeSlug);
        $product = Product::query()->where('slug', $productSlug)->firstOrFail();

        return new ResolvedSeoResource($resolver->forProduct($product));
    }

    public function category(string $storeSlug, string $categorySlug, SeoResolver $resolver): ResolvedSeoResource
    {
        $this->resolveStore($storeSlug);
        $category = Category::query()->where('slug', $categorySlug)->firstOrFail();

        return new ResolvedSeoResource($resolver->forCategory($category));
    }

    public function brand(string $storeSlug, string $brandSlug, SeoResolver $resolver): ResolvedSeoResource
    {
        $this->resolveStore($storeSlug);
        $brand = Brand::query()->where('slug', $brandSlug)->firstOrFail();

        return new ResolvedSeoResource($resolver->forBrand($brand));
    }

    public function contentPage(string $storeSlug, string $pageSlug, SeoResolver $resolver): ResolvedSeoResource
    {
        $this->resolveStore($storeSlug);
        // Module 16 Data Integrity Rule #9: "Draft content must never
        // leak into normal public rendering" — only Published pages
        // are ever resolvable through this public endpoint.
        $page = ContentPage::query()->where('slug', $pageSlug)->where('status', ContentPageStatus::Published->value)->firstOrFail();

        return new ResolvedSeoResource($resolver->forContentPage($page));
    }

    public function productStructuredData(string $storeSlug, string $productSlug, StructuredDataService $structuredData): array
    {
        $this->resolveStore($storeSlug);
        $product = Product::query()->where('slug', $productSlug)->firstOrFail();

        return $structuredData->forProduct($product);
    }

    private function resolveStore(string $storeSlug): Store
    {
        $store = Store::query()->where('slug', $storeSlug)->firstOrFail();
        app(TenantContext::class)->resolveToStore($store->id);

        return $store;
    }
}
