<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Services;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\ProductVisibility;
use App\Domain\Seo\Models\ContentPage;
use App\Domain\Seo\Services\ResolvedSeo;
use App\Domain\Seo\Services\SeoResolver;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tenancy\Models\Store;
use App\Support\HtmlSanitizer;
use Illuminate\Support\Str;

/**
 * Module 05 — the public shape of catalog data. Everything a shopper
 * receives is built here, so what is never published is decided in one
 * place: no internal ids (ADR-003: public ids and slugs only), no cost
 * prices, no exact stock counts, and rich text only after the
 * parser-based HtmlSanitizer.
 */
final class StorefrontPresenter
{
    public function __construct(
        private readonly SeoResolver $seo,
        private readonly HtmlSanitizer $sanitizer,
        private readonly ConfigService $config,
    ) {}

    /**
     * The language of the current request. Read from the container on every
     * call, never kept: a controller (and with it this presenter) may outlive
     * one request — a long-running worker, or the router's cached controller —
     * and must not answer in an earlier visitor's language.
     */
    private function locale(): StorefrontLocale
    {
        return app(StorefrontLocale::class);
    }

    private function translations(): \App\Domain\Settings\Services\TranslationService
    {
        return app(\App\Domain\Settings\Services\TranslationService::class);
    }

    /**
     * Phase B38 (Module 06 §101, Module 07 §99): a field in the visitor's
     * language, or the original when it has no translation.
     */
    private function tr(string $type, int $id, string $field, ?string $original): ?string
    {
        return $this->translations()->value($type, $id, $field, $original, $this->locale()->current(), $this->locale()->default());
    }

    /**
     * Loads the translations of a list of products (and their brands) with
     * one query each, before their cards are made.
     *
     * @param iterable<Product> $products
     */
    public function primeProducts(iterable $products): void
    {
        if ($this->locale()->isDefault()) {
            return;
        }
        $list = collect($products);
        $this->translations()->prime('product', $list->pluck('id')->all(), $this->locale()->current());
        $this->translations()->prime('brand', $list->pluck('brand_id')->filter()->unique()->all(), $this->locale()->current());
    }

    /**
     * Phase B42 (Module 07 §38): loads the translations of these attributes
     * and their values with two queries, before they are shown.
     *
     * @param iterable<\App\Domain\Catalog\Models\Attribute> $attributes
     */
    public function primeAttributes(iterable $attributes): void
    {
        if ($this->locale()->isDefault()) {
            return;
        }
        $list = collect($attributes);
        $this->translations()->prime('attribute', $list->pluck('id')->all(), $this->locale()->current());
        $this->translations()->prime('attribute_value', $list->flatMap(fn ($a) => $a->values->pluck('id'))->all(), $this->locale()->current());
    }

    public function attributeName(\App\Domain\Catalog\Models\Attribute $attribute): string
    {
        return (string) $this->tr('attribute', $attribute->id, 'name', $attribute->name);
    }

    public function attributeValue(\App\Domain\Catalog\Models\AttributeValue $value): string
    {
        return (string) $this->tr('attribute_value', $value->id, 'value', $value->value);
    }

    public function currency(): string
    {
        return (string) $this->config->get('store.default_currency');
    }

    /** @return array<string, mixed> */
    /**
     * Cards for a list of products, translations loaded in one go (Phase B38).
     *
     * @param iterable<Product> $products
     * @return list<array<string, mixed>>
     */
    public function cards(iterable $products): array
    {
        $list = collect($products);
        $this->primeProducts($list);

        return $list->map(fn (Product $product) => $this->card($product))->values()->all();
    }

    public function card(Product $product): array
    {
        $from = $this->int($product->getAttribute('price_from_minor'));
        $to = $this->int($product->getAttribute('price_to_minor'));
        $singlePrice = $from === $to;
        $onSale = (bool) $product->getAttribute('on_sale');

        return [
            'id' => $product->public_id,
            'slug' => $product->slug,
            'name' => $this->tr('product', $product->id, 'name', $product->name),
            'summary' => ($summary = $this->tr('product', $product->id, 'short_description', $product->short_description)) !== null ? Str::limit(strip_tags($summary), 160) : null,
            'brand' => $product->brand !== null ? ['name' => $this->tr('brand', $product->brand->id, 'name', $product->brand->name), 'slug' => $product->brand->slug] : null,
            'price' => [
                'currency' => $product->currency ?? $this->currency(),
                'amount_minor' => $from,
                'max_amount_minor' => $singlePrice ? null : $to,
                // A "was" price only when it is unambiguous: one price, on sale.
                'compare_at_minor' => $onSale && $singlePrice && ! $product->getAttribute('has_variants') ? $product->price_minor : null,
                'on_sale' => $onSale,
            ],
            'image' => ($image = $product->images->first()) !== null ? $this->image($image) : null,
            // Module 17 §26 / Module 18 §13 (Phase B36): the second image the card shows on hover.
            'hover_image' => ($second = $product->images->get(1)) !== null ? $this->image($second) : null,
            // A product with variants is chosen on its page; one without can go to the cart from the card.
            'has_variants' => (bool) $product->getAttribute('has_variants'),
            'in_stock' => (bool) $product->getAttribute('in_stock'),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Product $product): array
    {
        $store = $product->store;
        $variants = $product->variants;
        $variantImages = $product->images->whereNotNull('product_variant_id')->groupBy('product_variant_id');
        $purchasableVisibility = $product->visibility === ProductVisibility::Public;

        $presentedVariants = $variants->map(function (ProductVariant $variant) use ($product, $store, $variantImages, $purchasableVisibility) {
            $price = $variant->effectivePriceMinor();
            $availability = $this->availability($this->catalog()->stockLevel($product->id, $variant->id), $store);

            return [
                'id' => $variant->public_id,
                'sku' => $variant->sku,
                'options' => $variant->option_values ?? [],
                'price_minor' => $price,
                'compare_at_minor' => $price !== null && $variant->price_minor !== null && $price < $variant->price_minor ? $variant->price_minor : null,
                'availability' => $availability,
                'purchasable' => $purchasableVisibility && $price !== null && $availability !== 'out_of_stock',
                'image_id' => ($image = $variantImages->get($variant->id)?->first()) !== null ? $image->public_id : null,
            ];
        })->values()->all();

        $ownPrice = $product->effectivePriceMinor();
        $ownAvailability = $variants->isEmpty() ? $this->availability($this->catalog()->stockLevel($product->id, null), $store) : null;

        return [
            ...$this->card($product),
            'sku' => $product->sku,
            'description_html' => ($description = $this->tr('product', $product->id, 'description', $product->description)) !== null ? $this->sanitizer->sanitize($description) : null,
            'images' => $product->images->map(fn (ProductImage $image) => $this->image($image))->values()->all(),
            'options' => $this->optionAxes($variants->all()),
            'variants' => $presentedVariants,
            // For a product without variants, the product itself is the unit bought.
            'availability' => $ownAvailability,
            'purchasable' => $variants->isEmpty()
                ? $purchasableVisibility && $ownPrice !== null && $ownAvailability !== 'out_of_stock'
                : collect($presentedVariants)->contains('purchasable', true),
            'breadcrumbs' => $this->breadcrumbs($product->primaryCategory),
        ];
    }

    /**
     * @param array<int, int> $counts
     * @return list<array<string, mixed>> top-level categories with their visible children
     */
    public function categoryTree(array $counts): array
    {
        if (! $this->locale()->isDefault()) {
            $this->translations()->prime('category', $this->catalog()->visibleCategories()->pluck('id')->all(), $this->locale()->current());
        }
        $children = $this->catalog()->visibleCategories()->groupBy('parent_id');
        $build = function (Category $category, int $depth) use (&$build, $children, $counts): array {
            return [
                ...$this->category($category, $counts[$category->id] ?? 0),
                'children' => $depth >= 2 ? [] : $children->get($category->id, collect())
                    ->map(fn (Category $child) => $build($child, $depth + 1))->values()->all(),
            ];
        };
        // Roots only: a category under a hidden parent is unreachable,
        // exactly as it is for the category filter.
        return $this->catalog()->visibleCategories()
            ->whereNull('parent_id')
            ->map(fn (Category $category) => $build($category, 0))
            ->values()->all();
    }

    /** @return array<string, mixed> */
    public function category(Category $category, ?int $productCount = null): array
    {
        return array_filter([
            'id' => $category->public_id,
            'slug' => $category->slug,
            'name' => $this->tr('category', $category->id, 'name', $category->name),
            'description' => $this->tr('category', $category->id, 'description', $category->description),
            'product_count' => $productCount,
            'in_menu' => in_array($category->visibility, ['public', 'navigation_only'], true),
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> Phase B39: a collection, in the visitor's language */
    public function collection(\App\Domain\Catalog\Models\Collection $collection): array
    {
        return [
            'slug' => $collection->slug,
            'name' => $this->tr('collection', $collection->id, 'name', $collection->name),
            'description' => $this->tr('collection', $collection->id, 'description', $collection->description),
        ];
    }

    /** @return array<string, mixed> */
    public function brand(Brand $brand): array
    {
        return ['slug' => $brand->slug, 'name' => $this->tr('brand', $brand->id, 'name', $brand->name), 'description' => $this->tr('brand', $brand->id, 'description', $brand->description)];
    }

    /** @return array<string, mixed> */
    public function page(ContentPage $page): array
    {
        return [
            'slug' => $page->slug,
            'title' => $page->title,
            'body_html' => $this->sanitizer->sanitize($page->body),
            'published_at' => $page->published_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function seo(ResolvedSeo $seo, ?array $structuredData = null): array
    {
        return [
            'title' => $seo->title,
            'description' => $seo->metaDescription,
            'canonical' => $seo->canonicalUrl,
            'og_title' => $seo->ogTitle,
            'og_description' => $seo->ogDescription,
            'og_image' => $seo->ogImageUrl,
            'robots' => $seo->robotsContent(),
            'structured_data' => $structuredData,
        ];
    }

    /** schema.org Product with offers that match what the page sells. */
    public function productStructuredData(Product $product, array $detail): array
    {
        $seo = $this->seo->forProduct($product);
        $currency = $detail['price']['currency'];
        $available = $detail['purchasable'];
        $money = fn (?int $minor) => $minor === null ? null : \App\Domain\Settings\Services\Currencies::amount($minor, (string) $currency);

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $detail['name'],
            'description' => $seo->metaDescription,
            'sku' => $product->sku,
            'url' => $seo->canonicalUrl,
            'image' => array_column($detail['images'], 'url') ?: null,
            'brand' => $detail['brand'] !== null ? ['@type' => 'Brand', 'name' => $detail['brand']['name']] : null,
            'offers' => $detail['price']['amount_minor'] === null ? null : ($detail['price']['max_amount_minor'] !== null ? [
                '@type' => 'AggregateOffer',
                'priceCurrency' => $currency,
                'lowPrice' => $money($detail['price']['amount_minor']),
                'highPrice' => $money($detail['price']['max_amount_minor']),
                'offerCount' => count($detail['variants']),
                'availability' => $available ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            ] : [
                '@type' => 'Offer',
                'priceCurrency' => $currency,
                'price' => $money($detail['price']['amount_minor']),
                'availability' => $available ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'url' => $seo->canonicalUrl,
            ]),
        ], fn ($value) => $value !== null);
    }

    /**
     * in_stock | low_stock | out_of_stock | backorder. Exact counts are
     * never published; untracked stock counts as in stock.
     */
    public function availability(?int $units, Store $store): string
    {
        return match (true) {
            $units === null => 'in_stock',
            $units > (int) config('storefront.low_stock_threshold') => 'in_stock',
            $units > 0 => 'low_stock',
            $store->allow_overselling => 'backorder',
            default => 'out_of_stock',
        };
    }

    /** @return array<string, mixed> */
    public function image(ProductImage $image): array
    {
        return [
            'id' => $image->public_id,
            'url' => $image->url(),
            'alt' => $image->alt,
            'width' => $image->width,
            'height' => $image->height,
        ];
    }

    /**
     * The option axes a shopper chooses from (e.g. color, size), with
     * values in first-seen (variant) order.
     *
     * @param list<ProductVariant> $variants
     * @return list<array{name: string, values: list<string>}>
     */
    private function optionAxes(array $variants): array
    {
        $axes = [];

        foreach ($variants as $variant) {
            foreach ($variant->option_values ?? [] as $name => $value) {
                if (is_scalar($value) && ! in_array((string) $value, $axes[(string) $name] ?? [], true)) {
                    $axes[(string) $name][] = (string) $value;
                }
            }
        }

        // Axes by name: option_values is a MySQL JSON column, which does
        // not keep the order keys were written in, so the only stable
        // order is one we choose.
        ksort($axes, SORT_NATURAL | SORT_FLAG_CASE);

        return array_map(fn (string $name, array $values) => ['name' => $name, 'values' => $values], array_keys($axes), array_values($axes));
    }

    /** @return list<array{name: string, slug: string}> */
    private function breadcrumbs(?Category $category): array
    {
        $visible = $this->catalog()->visibleCategories()->keyBy('id');

        if ($category === null || ! $visible->has($category->id)) {
            return [];
        }

        $trail = [];
        for ($current = $visible->get($category->id); $current !== null; $current = $current->parent_id !== null ? $visible->get($current->parent_id) : null) {
            array_unshift($trail, ['name' => $this->tr('category', $current->id, 'name', $current->name), 'slug' => $current->slug]);
        }

        return $trail;
    }

    private function int(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * The catalog of this request (scoped): the controller holding this service is cached on
     * its route, so a catalog kept in a property would carry one request's memos into the next.
     */
    private function catalog(): StorefrontCatalog
    {
        return app(StorefrontCatalog::class);
    }
}
