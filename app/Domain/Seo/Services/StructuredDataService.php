<?php

declare(strict_types=1);

namespace App\Domain\Seo\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Tenancy\Models\Store;

/**
 * Module 16 §14 "Structured Data / Schema.org" — only the types this
 * milestone builds (Product, Organization, WebSite, BreadcrumbList).
 * Every value is generated from AUTHORITATIVE entity data
 * (Product/Store) — Non-Negotiable: "do not accept arbitrary JSON-LD
 * from clients."
 */
final class StructuredDataService
{
    public function __construct(private readonly SeoResolver $resolver) {}

    public function forProduct(Product $product): array
    {
        $seo = $this->resolver->forProduct($product);

        return [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->name,
            'description' => $seo->metaDescription,
            'sku' => $product->sku,
            'url' => $seo->canonicalUrl,
            'offers' => [
                '@type' => 'Offer',
                'priceCurrency' => $product->currency,
                'price' => \App\Domain\Settings\Services\Currencies::amount((int) $product->price_minor, (string) $product->currency),
                'availability' => 'https://schema.org/InStock',
            ],
        ];
    }

    public function forOrganization(Store $store): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $store->name,
            'url' => $this->resolver->forStoreHome($store)->canonicalUrl,
        ];
    }

    public function forWebsite(Store $store): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $store->name,
            'url' => $this->resolver->forStoreHome($store)->canonicalUrl,
        ];
    }

    /** @param list<array{name: string, url: string}> $items */
    public function forBreadcrumbs(array $items): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn ($item, $index) => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item['name'],
                'item' => $item['url'],
            ], $items, array_keys($items)),
        ];
    }
}
