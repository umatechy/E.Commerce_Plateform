<?php

declare(strict_types=1);

namespace App\Domain\Seo\Services;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductStatus;
use App\Domain\Catalog\Models\ProductVisibility;
use App\Domain\Seo\Models\ContentPage;
use App\Domain\Seo\Models\ContentPageStatus;
use App\Domain\Seo\Models\RobotsDirective;
use App\Domain\Seo\Models\SeoableType;
use App\Domain\Seo\Models\SeoSetting;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Support\Collection;

/**
 * Module 16 §17-19 "Sitemap / Sitemap Tenant Isolation / Sitemap
 * Performance". Only publicly indexable content is ever included
 * (Non-Negotiable): draft/unpublished content pages, non-active/
 * private-visibility products, and any entity explicitly marked
 * `noindex` are all excluded. URLs are built via the SAME
 * SeoResolver canonical-URL logic every other SEO surface uses — never
 * a second, duplicated URL-building path.
 */
final class SitemapService
{
    public function __construct(private readonly SeoResolver $resolver) {}

    /** @return list<array{loc: string, lastmod: ?string}> */
    public function urlsFor(Store $store): array
    {
        $urls = [];

        $products = Product::query()
            ->where('status', ProductStatus::Active->value)
            ->where('visibility', ProductVisibility::Public->value)
            ->get();
        foreach ($products as $product) {
            if ($this->isIndexable(SeoableType::Product, $product->id)) {
                $urls[] = ['loc' => $this->resolver->forProduct($product)->canonicalUrl, 'lastmod' => $product->updated_at?->toAtomString()];
            }
        }

        foreach (Category::query()->get() as $category) {
            if ($this->isIndexable(SeoableType::Category, $category->id)) {
                $urls[] = ['loc' => $this->resolver->forCategory($category)->canonicalUrl, 'lastmod' => $category->updated_at?->toAtomString()];
            }
        }

        foreach (Brand::query()->get() as $brand) {
            if ($this->isIndexable(SeoableType::Brand, $brand->id)) {
                $urls[] = ['loc' => $this->resolver->forBrand($brand)->canonicalUrl, 'lastmod' => $brand->updated_at?->toAtomString()];
            }
        }

        foreach (ContentPage::query()->where('status', ContentPageStatus::Published->value)->get() as $page) {
            if ($this->isIndexable(SeoableType::ContentPage, $page->id)) {
                $urls[] = ['loc' => $this->resolver->forContentPage($page)->canonicalUrl, 'lastmod' => $page->updated_at?->toAtomString()];
            }
        }

        $urls[] = ['loc' => $this->resolver->forStoreHome($store)->canonicalUrl, 'lastmod' => null];

        return $urls;
    }

    /** @param list<array{loc: string, lastmod: ?string}> $urls @return list<Collection> */
    public function chunk(array $urls): array
    {
        return collect($urls)->chunk(config('seo.sitemap_chunk_size'))->values()->all();
    }

    public function toXml(array $urls): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>');

        foreach ($urls as $entry) {
            $url = $xml->addChild('url');
            $url->addChild('loc', htmlspecialchars($entry['loc'], ENT_XML1));
            if ($entry['lastmod'] !== null) {
                $url->addChild('lastmod', $entry['lastmod']);
            }
        }

        return $xml->asXML();
    }

    private function isIndexable(SeoableType $type, int $id): bool
    {
        $setting = SeoSetting::query()->where('seoable_type', $type)->where('seoable_id', $id)->first();

        return ($setting->robots_index ?? RobotsDirective::Index) !== RobotsDirective::Noindex;
    }
}
