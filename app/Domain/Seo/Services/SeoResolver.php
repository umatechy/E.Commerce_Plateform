<?php

declare(strict_types=1);

namespace App\Domain\Seo\Services;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Seo\Models\ContentPage;
use App\Domain\Seo\Models\RobotsDirective;
use App\Domain\Seo\Models\SeoableType;
use App\Domain\Seo\Models\SeoSetting;
use App\Domain\Tenancy\Models\Store;

/**
 * Module 16 §7-8 "Defaults and Overrides / SEO Resolution Service" —
 * the ONE place the fallback hierarchy (entity-specific override →
 * store default → generated fallback) is evaluated. Never duplicated
 * across controllers (this milestone's own explicit instruction).
 */
final class SeoResolver
{
    public function forProduct(Product $product): ResolvedSeo
    {
        return $this->resolve(
            $product->store, SeoableType::Product, $product->id,
            fallbackTitle: $product->name,
            fallbackDescription: $this->truncate($product->short_description ?? $product->description),
            path: "products/{$product->slug}",
        );
    }

    public function forCategory(Category $category): ResolvedSeo
    {
        return $this->resolve(
            $category->store, SeoableType::Category, $category->id,
            fallbackTitle: $category->name,
            fallbackDescription: $this->truncate($category->description),
            path: "categories/{$category->slug}",
        );
    }

    public function forBrand(Brand $brand): ResolvedSeo
    {
        return $this->resolve(
            $brand->store, SeoableType::Brand, $brand->id,
            fallbackTitle: $brand->name,
            fallbackDescription: $this->truncate($brand->description),
            path: "brands/{$brand->slug}",
        );
    }

    public function forContentPage(ContentPage $page): ResolvedSeo
    {
        return $this->resolve(
            $page->store, SeoableType::ContentPage, $page->id,
            fallbackTitle: $page->title,
            fallbackDescription: $this->truncate(strip_tags($page->body)),
            path: "pages/{$page->slug}",
        );
    }

    public function forStoreHome(Store $store): ResolvedSeo
    {
        return $this->resolve(
            $store, SeoableType::Store, null,
            fallbackTitle: $store->name,
            fallbackDescription: null,
            path: '',
        );
    }

    private function resolve(Store $store, SeoableType $type, ?int $id, string $fallbackTitle, ?string $fallbackDescription, string $path): ResolvedSeo
    {
        $entitySetting = SeoSetting::query()->where('seoable_type', $type)->where('seoable_id', $id)->first();
        $storeDefault = $type === SeoableType::Store ? null : SeoSetting::query()->where('seoable_type', SeoableType::Store)->whereNull('seoable_id')->first();

        $title = $entitySetting?->title ?? $storeDefault?->title ?? $fallbackTitle;
        $description = $entitySetting?->meta_description ?? $storeDefault?->meta_description ?? $fallbackDescription;

        return new ResolvedSeo(
            title: $title,
            metaDescription: $description,
            canonicalUrl: $entitySetting?->canonical_override ?? $this->canonicalUrl($store, $path),
            ogTitle: $entitySetting?->og_title ?? $storeDefault?->og_title ?? $title,
            ogDescription: $entitySetting?->og_description ?? $storeDefault?->og_description ?? $description,
            ogImageUrl: $entitySetting?->og_image_url ?? $storeDefault?->og_image_url,
            robotsIndex: ($entitySetting?->robots_index ?? $storeDefault?->robots_index ?? RobotsDirective::Index)->value,
            robotsFollow: ($entitySetting?->robots_follow ?? $storeDefault?->robots_follow ?? RobotsDirective::Follow)->value,
        );
    }

    /**
     * Module 16 §10/§16 "Canonical URL Engine / Host Header Security"
     * — Non-Negotiable: NEVER built from the request's raw Host
     * header. See docs/development/b13-inspection-findings.md
     * "Architectural Decision — Canonical URL Base" for why a
     * configured base URL (not a verified per-tenant domain) is used —
     * no Module 19 domain-verification system exists yet.
     */
    /**
     * Module 19 §22 "Canonical URL Integration" (Phase B14) — REPLACES
     * B13's original placeholder body (config base URL + store slug as
     * a path segment) with the real, verified primary Domain. This is
     * the ONE method B14 changes in this entire class — every other
     * line of SeoResolver, and every other B13 service
     * (RedirectService, ContentSanitizer, SitemapService,
     * StructuredDataService), is untouched; they all inherit this fix
     * automatically because they call through this one method.
     *
     * Falls back to the old config-based placeholder ONLY if a store
     * somehow has no Active primary domain yet (should not happen in
     * practice — every store gets an auto-Active platform subdomain at
     * creation, Phase B14 — but this keeps SEO output well-formed
     * rather than throwing during, e.g., test setup that bypasses
     * StoreObserver).
     */
    private function canonicalUrl(Store $store, string $path): string
    {
        $domain = app(\App\Domain\Domains\Services\DomainResolverService::class)->primaryDomainFor($store);

        if ($domain !== null) {
            // Always https:// — the platform's own reverse-proxy/CDN
            // terminates TLS for every platform subdomain via a
            // wildcard certificate; Domain.ssl_status tracks CUSTOM
            // domain SSL provisioning specifically (Module 19 §17-18),
            // a separate concern from whether THIS canonical URL uses
            // https (documented decision, not an SSL-truthfulness gap).
            $base = 'https://'.$domain->normalized_hostname;

            return $path === '' ? $base : "{$base}/{$path}";
        }

        $base = rtrim(config('seo.storefront_base_url'), '/');
        $segments = array_filter([$store->slug, $path]);

        return $base.'/'.implode('/', $segments);
    }

    private function truncate(?string $text, int $length = 300): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        return mb_strlen($text) <= $length ? $text : mb_substr($text, 0, $length - 1).'…';
    }
}
