<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Services;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Seo\Models\ContentPage;
use App\Domain\Seo\Models\ContentPageStatus;
use App\Domain\Seo\Services\SeoResolver;
use App\Domain\Seo\Services\StructuredDataService;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Theme\Models\SectionType;
use App\Domain\Theme\Services\ThemeResolver;

/**
 * Module 05 — assembles each storefront view (shell, home, listing,
 * product, category, brand, page) from the catalog, theme (Module 17),
 * SEO (Module 16) and settings (Module 33), and caches it per store
 * (StorefrontCache). Both the JSON API and the server-rendered pages
 * read from here, so they can never disagree.
 */
final class StorefrontExperience
{
    private const CONTENT_SECTIONS = ['hero', 'featured_products', 'featured_categories', 'promotional_banner', 'best_sellers', 'sale_products', 'featured_brands', 'testimonials', 'faq', 'rich_text', 'trust_badges'];

    public function __construct(
        private readonly StorefrontPresenter $presenter,
        private readonly StorefrontCache $cache,
        private readonly ThemeResolver $themes,
        private readonly SeoResolver $seo,
        private readonly StructuredDataService $structuredData,
        private readonly ConfigService $config,
    ) {}

    /**
     * @param array{config?: mixed, custom_css?: ?string}|null $preview a theme draft to show instead of the
     *        published theme (Module 17 §19, Phase B32). Never cached: the cache holds what customers see.
     * @return array<string, mixed> branding, theme and navigation shared by every page
     */
    public function shell(Store $store, ?array $preview = null): array
    {
        if ($preview !== null) {
            return $this->buildShell($store, $preview);
        }

        return $this->cache->remember($store->id, 'shell', fn () => $this->buildShell($store, $this->themes->resolvePublished($store)));
    }

    /**
     * @param array<string, mixed>|null $theme
     * @return array<string, mixed>
     */
    private function buildShell(Store $store, ?array $theme): array
    {
        $config = $theme['config'] ?? [];
        $branding = $config['branding'] ?? [];
        // Module 17 §39/§51 (Phase B36): the inherited, package-checked presentation.
        $presentation = $this->themes->present($config, $theme['theme_key'] ?? null);
        $announcement = collect($presentation['sections'])->firstWhere('type', SectionType::AnnouncementBar->value);
        $language = app(StorefrontLocale::class);
        $tagline = $branding['translations'][$language->current()]['tagline'] ?? $branding['tagline'] ?? null;
        $counts = $this->catalog()->productCountsByCategory();

        return [
            'store' => [
                'name' => $store->name,
                'slug' => $store->slug,
                'currency' => $this->presenter->currency(),
                // Phase B38: the language of this page (LOC-001); `languages` are the ones offered.
                'locale' => $language->current(),
                'timezone' => $this->config->get('store.timezone'), // dates are shown in the store's timezone (Module 33 §50.3)
                'tagline' => $tagline,
                'logo_url' => $branding['logo_url'] ?? null,
                'favicon_url' => $branding['favicon_url'] ?? null,
                'social_links' => $branding['social_links'] ?? (object) [],
            ],
            'theme' => [
                'key' => $presentation['theme']['key'],
                'name' => $presentation['theme']['name'],
                'tokens' => $presentation['tokens'],
                'layout' => $presentation['layout'],
                'motion' => $presentation['motion'],
                'custom_css' => $theme['custom_css'] ?? null,
            ],
            'announcement' => $announcement !== null ? ($this->localized($announcement['config'] ?? [])['message'] ?? null) : null,
            'language' => [
                'current' => $language->current(),
                'default' => $language->default(),
                'dir' => $language->direction(),
                'offered' => \App\Domain\Settings\Services\Locales::describe($language->offered()),
            ],
            'navigation' => [
                'categories' => array_values(array_filter(
                    $this->presenter->categoryTree($counts),
                    fn (array $category) => $category['in_menu'],
                )),
                'pages' => $this->publishedPages()->map(fn (ContentPage $page) => ['slug' => $page->slug, 'title' => $page->title])->values()->all(),
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $preview a theme draft (Module 17 §19); never cached
     * @return array<string, mixed>
     */
    public function home(Store $store, ?array $preview = null): array
    {
        if ($preview !== null) {
            return $this->buildHome($store, $preview);
        }

        return $this->cache->remember($store->id, 'home', fn () => $this->buildHome($store, $this->themes->resolvePublished($store)));
    }

    /**
     * @param array<string, mixed>|null $theme
     * @return array<string, mixed>
     */
    private function buildHome(Store $store, ?array $theme): array
    {
        $sections = $this->themes->present($theme['config'] ?? [], $theme['theme_key'] ?? null)['sections'];
        // Every store starts on the default theme, which has only a
        // header and footer: until the owner adds content sections,
        // the home page shows sensible defaults instead of nothing.
        if (! collect($sections)->contains(fn (array $s) => in_array($s['type'], self::CONTENT_SECTIONS, true))) {
            $sections = [...$sections, ...$this->defaultSections($store)];
        }
        $counts = $this->catalog()->productCountsByCategory();

        $resolved = [];
        foreach ($sections as $section) {
            $config = $this->localized($section['config'] ?? []);
            $resolved[] = match ($section['type']) {
                SectionType::Hero->value, SectionType::PromotionalBanner->value => ['type' => $section['type'], ...$config],
                // Phase B36 (Module 17 §25): the advanced sections.
                SectionType::BestSellers->value => [
                    'type' => $section['type'],
                    'heading' => $config['heading'] ?? null, // Phase B38: no heading set — the storefront shows its own, in the visitor's language
                    'products' => $this->presenter->cards($this->catalog()->bestSellers((int) ($config['limit'] ?? 8))),
                ],
                SectionType::SaleProducts->value => [
                    'type' => $section['type'],
                    'heading' => $config['heading'] ?? null, // Phase B38: no heading set — the storefront shows its own, in the visitor's language
                    'products' => $this->presenter->cards($this->catalog()->onSale((int) ($config['limit'] ?? 8))),
                ],
                SectionType::FeaturedBrands->value => [
                    'type' => $section['type'],
                    'heading' => $config['heading'] ?? null, // Phase B38: no heading set — the storefront shows its own, in the visitor's language
                    'brands' => $this->catalog()->brands()->take((int) ($config['limit'] ?? 12))->map(fn (\App\Domain\Catalog\Models\Brand $b) => ['name' => $b->name, 'slug' => $b->slug])->values()->all(),
                ],
                SectionType::Testimonials->value, SectionType::Faq->value, SectionType::RichText->value, SectionType::TrustBadges->value => ['type' => $section['type'], ...$config],
                SectionType::FeaturedProducts->value => $this->featuredSection($section['type'], $config),
                SectionType::FeaturedCategories->value => [
                    'type' => $section['type'],
                    'heading' => $config['heading'] ?? null, // Phase B38: no heading set — the storefront shows its own, in the visitor's language
                    'categories' => array_slice(array_values(array_filter(
                        $this->presenter->categoryTree($counts),
                        fn (array $c) => ($c['product_count'] ?? 0) > 0,
                    )), 0, (int) ($config['limit'] ?? 6)),
                ],
                // Header/footer are the layout itself; the announcement
                // is in the shell; a newsletter needs a sign-up
                // endpoint that does not exist yet (not rendered).
                default => null,
            };
        }

        return [
            'sections' => array_values(array_filter($resolved)),
            'seo' => $this->presenter->seo($this->seo->forStoreHome($store), [
                $this->structuredData->forOrganization($store),
                $this->structuredData->forWebsite($store),
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $filters validated listing filters
     * @return array<string, mixed>
     */
    public function listing(Store $store, array $filters): array
    {
        ksort($filters);

        return $this->cache->remember($store->id, 'listing:'.md5((string) json_encode($filters)), function () use ($filters) {
            // Phase B41 (Module 07 §18, §47–49): a category page filters by the attributes its
            // category exposes; any other `attr` in the address is ignored, never trusted.
            $attributes = $this->filterAttributes($filters['category'] ?? null);
            $search = $filters;
            unset($search['attr']);
            $search['attributes'] = $this->resolveAttributeFilters((array) ($filters['attr'] ?? []), $attributes);
            $page = $this->catalog()->search($search);

            return [
                'products' => $this->presenter->cards($page->items()),
                'pagination' => [
                    'page' => $page->currentPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'last_page' => $page->lastPage(),
                ],
                'filters' => $attributes === [] ? [] : $this->presentFilters($attributes, $search),
            ];
        });
    }

    /**
     * The active filter attributes of a visible category (its own list or its
     * nearest parent's), with their values.
     *
     * @return list<\App\Domain\Catalog\Models\Attribute>
     */
    private function filterAttributes(?string $categorySlug): array
    {
        $category = $categorySlug !== null ? $this->catalog()->category($categorySlug) : null;
        if ($category === null) {
            return [];
        }

        return app(\App\Domain\Catalog\Services\CategoryAttributes::class)->effective($category)
            ->filter(fn ($ca) => $ca->is_filter && $ca->attribute->type->isFilterable())
            ->map(fn ($ca) => $ca->attribute)->values()->all();
    }

    /**
     * `attr[key]=slug,slug` (choices), `attr[key]=min-max` (numbers, either side may be empty),
     * `attr[key]=1` (yes/no). Unknown keys and values are dropped.
     *
     * @param array<string, mixed> $raw
     * @param list<\App\Domain\Catalog\Models\Attribute> $attributes
     * @return array<int, array<string, mixed>>
     */
    private function resolveAttributeFilters(array $raw, array $attributes): array
    {
        $out = [];
        foreach ($attributes as $attribute) {
            $value = $raw[$attribute->key] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }
            if ($attribute->type->hasValues()) {
                $slugs = array_slice(explode(',', $value), 0, 20);
                $ids = $attribute->values->filter(fn ($v) => $v->is_active && in_array($v->slug, $slugs, true))->pluck('id')->all();
                if ($ids !== []) {
                    $out[$attribute->id] = ['values' => $ids];
                }
            } elseif ($attribute->type === \App\Domain\Catalog\Models\AttributeType::Boolean) {
                if ($value === '1') {
                    $out[$attribute->id] = ['yes' => true];
                }
            } elseif (preg_match('/^(\d{1,12}(?:\.\d{1,4})?)?-(\d{1,12}(?:\.\d{1,4})?)?$/', $value, $m) === 1 && ($m[1] ?? '').($m[2] ?? '') !== '') {
                $out[$attribute->id] = array_filter(['min' => ($m[1] ?? '') !== '' ? (float) $m[1] : null, 'max' => ($m[2] ?? '') !== '' ? (float) $m[2] : null], fn ($v) => $v !== null);
            }
        }

        return $out;
    }

    /**
     * @param list<\App\Domain\Catalog\Models\Attribute> $attributes
     * @param array<string, mixed> $search
     * @return list<array<string, mixed>>
     */
    private function presentFilters(array $attributes, array $search): array
    {
        $facets = $this->catalog()->attributeFacets($search, $attributes);
        $chosen = (array) ($search['attributes'] ?? []);
        $out = [];
        foreach ($attributes as $attribute) {
            $facet = $facets[$attribute->id] ?? [];
            $selected = $chosen[$attribute->id] ?? null;
            $picked = (array) ($selected['values'] ?? []);
            $entry = ['key' => $attribute->key, 'name' => $attribute->name, 'type' => $attribute->type->value, 'unit' => $attribute->unit];
            if ($attribute->type->hasValues()) {
                $entry['options'] = $attribute->values
                    ->filter(fn ($v) => $v->is_active && (($facet['counts'][$v->id] ?? 0) > 0 || in_array($v->id, $picked, true)))
                    ->map(fn ($v) => ['slug' => $v->slug, 'value' => $v->value, 'color_code' => $v->color_code, 'count' => $facet['counts'][$v->id] ?? 0, 'selected' => in_array($v->id, $picked, true)])
                    ->values()->all();
                if ($entry['options'] === []) {
                    continue; // nothing to choose: no empty filter (§77)
                }
            } elseif ($attribute->type === \App\Domain\Catalog\Models\AttributeType::Boolean) {
                $entry['yes'] = $facet['yes'] ?? 0;
                $entry['selected'] = $selected !== null;
                if ($entry['yes'] === 0 && ! $entry['selected']) {
                    continue;
                }
            } else {
                $entry['range'] = ['min' => $facet['min'] ?? null, 'max' => $facet['max'] ?? null];
                $entry['selected'] = $selected === null ? null : ['min' => $selected['min'] ?? null, 'max' => $selected['max'] ?? null];
                if ($entry['range']['min'] === null && $selected === null) {
                    continue;
                }
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Phase B41 (Module 07 §41, §46): a product's specifications for its page,
     * in the attributes' order; inactive attributes and values are left out.
     *
     * @return list<array{name: string, group: ?string, value: string, colors: list<array{name: string, code: string}>}>
     */
    private function specifications(Product $product): array
    {
        $rows = \App\Domain\Catalog\Models\ProductAttributeValue::query()->where('product_id', $product->id)
            ->with(['attribute', 'choice'])->get()
            ->filter(fn ($row) => $row->attribute !== null && $row->attribute->is_active && ($row->attribute_value_id === null || ($row->choice !== null && $row->choice->is_active)))
            ->groupBy('attribute_id')
            ->sortBy(fn ($group) => sprintf('%08d|%s', $group->first()->attribute->sort_order, mb_strtolower($group->first()->attribute->name)));

        $out = [];
        foreach ($rows as $group) {
            $attribute = $group->first()->attribute;
            $first = $group->first();
            $value = match ($attribute->type) {
                \App\Domain\Catalog\Models\AttributeType::Numeric => rtrim(rtrim(number_format((float) $first->number_value, 4, '.', ''), '0'), '.').($attribute->unit ? ' '.$attribute->unit : ''),
                \App\Domain\Catalog\Models\AttributeType::Boolean => $first->bool_value ? 'yes' : 'no',
                \App\Domain\Catalog\Models\AttributeType::Text => (string) $first->text_value,
                default => $group->sortBy(fn ($row) => $row->choice->sort_order)->map(fn ($row) => $row->choice->value)->implode(', '),
            };
            $colors = $attribute->type === \App\Domain\Catalog\Models\AttributeType::Color
                ? $group->filter(fn ($row) => $row->choice->color_code !== null)->map(fn ($row) => ['name' => $row->choice->value, 'code' => (string) $row->choice->color_code])->values()->all()
                : [];
            $out[] = ['name' => $attribute->name, 'group' => $attribute->group, 'value' => $value, 'colors' => $colors];
        }

        return $out;
    }

    /** @return array<string, mixed> category tree (with counts) and brands for filter menus */
    public function facets(Store $store): array
    {
        return $this->cache->remember($store->id, 'facets', fn () => [
            'categories' => $this->presenter->categoryTree($this->catalog()->productCountsByCategory()),
            'brands' => $this->catalog()->brands()->map(fn (Brand $b) => $this->presenter->brand($b))->values()->all(),
        ]);
    }

    /** @return ?array<string, mixed> */
    public function product(Store $store, string $slug): ?array
    {
        return $this->cache->remember($store->id, 'product:'.$slug, function () use ($slug) {
            $product = $this->catalog()->product($slug);

            if ($product === null) {
                return null;
            }

            $detail = $this->presenter->detail($product);
            $breadcrumbs = collect($detail['breadcrumbs'])->map(fn (array $crumb) => [
                'name' => $crumb['name'],
                'url' => $this->seo->forCategory(Category::query()->where('slug', $crumb['slug'])->firstOrFail())->canonicalUrl,
            ])->all();

            // Phase B39 (Module 06 §38): the products chosen for it; without any
            // related ones, others of its category not already shown above.
            $crossSell = $this->catalog()->relatedTo($product->id, ['cross_sell'], 8);
            $upSell = $this->catalog()->relatedTo($product->id, ['up_sell'], 8);
            $related = $this->catalog()->relatedTo($product->id, ['related', 'alternative'], 8);
            if ($related->isEmpty()) {
                $shown = $crossSell->merge($upSell)->pluck('id')->all();
                $related = $this->catalog()->newest(4 + count($shown), $product->id, $product->primary_category_id)
                    ->reject(fn (Product $p) => in_array($p->id, $shown, true))->take(4)->values();
            }

            return [
                'product' => [...$detail, 'specifications' => $this->specifications($product)],
                'related' => $this->presenter->cards($related),
                'cross_sell' => $this->presenter->cards($crossSell),
                'up_sell' => $this->presenter->cards($upSell),
                'seo' => $this->presenter->seo($this->seo->forProduct($product), array_values(array_filter([
                    $this->presenter->productStructuredData($product, $detail),
                    $breadcrumbs !== [] ? $this->structuredData->forBreadcrumbs($breadcrumbs) : null,
                ]))),
            ];
        });
    }

    /** @return ?array<string, mixed> */
    public function category(Store $store, string $slug): ?array
    {
        return $this->cache->remember($store->id, 'category:'.$slug, function () use ($slug) {
            $category = $this->catalog()->category($slug);

            if ($category === null) {
                return null;
            }

            $counts = $this->catalog()->productCountsByCategory();
            $children = $this->catalog()->visibleCategories()->where('parent_id', $category->id);

            return [
                'category' => [
                    ...$this->presenter->category($category, $counts[$category->id] ?? 0),
                    'children' => $children->map(fn (Category $c) => $this->presenter->category($c, $counts[$c->id] ?? 0))->values()->all(),
                ],
                'seo' => $this->presenter->seo($this->seo->forCategory($category)),
            ];
        });
    }

    /**
     * Phase B39 (Module 06 §34): a live collection's page head. Its products
     * come from listing() with the `collection` filter.
     *
     * @return ?array<string, mixed>
     */
    public function collection(Store $store, string $slug): ?array
    {
        return $this->cache->remember($store->id, 'collection:'.$slug, function () use ($slug) {
            $collection = $this->catalog()->liveCollection($slug);

            return $collection === null ? null : ['collection' => $this->presenter->collection($collection)];
        });
    }

    /** @return list<array<string, mixed>> the live collections, for headless storefronts */
    public function collections(Store $store): array
    {
        return $this->cache->remember($store->id, 'collections', fn () => $this->catalog()->liveCollections()
            ->map(fn (\App\Domain\Catalog\Models\Collection $c) => $this->presenter->collection($c))->values()->all());
    }

    /**
     * Phase B39: the "featured products" section shows the newest products
     * (as before), those marked featured, or a live collection's — in the
     * collection's own order. A collection that is not live shows nothing.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function featuredSection(string $type, array $config): array
    {
        $limit = (int) ($config['limit'] ?? 8);
        $source = $config['source'] ?? 'newest';
        $products = match ($source) {
            'featured' => $this->catalog()->featured($limit),
            'collection' => isset($config['collection']) && $this->catalog()->liveCollection((string) $config['collection']) !== null
                ? collect($this->catalog()->search(['collection' => (string) $config['collection'], 'per_page' => $limit])->items())
                : collect(),
            default => $this->catalog()->newest($limit),
        };

        return [
            'type' => $type,
            'source' => $source,
            'heading' => $config['heading'] ?? null, // Phase B38: no heading set — the storefront shows its own, in the visitor's language
            'collection' => $source === 'collection' ? ($config['collection'] ?? null) : null,
            'products' => $this->presenter->cards($products->all()),
        ];
    }

    /** @return ?array<string, mixed> */
    public function brand(Store $store, string $slug): ?array
    {
        return $this->cache->remember($store->id, 'brand:'.$slug, function () use ($slug) {
            $brand = $this->catalog()->brand($slug);

            return $brand === null ? null : [
                'brand' => $this->presenter->brand($brand),
                'seo' => $this->presenter->seo($this->seo->forBrand($brand)),
            ];
        });
    }

    /** @return ?array<string, mixed> */
    public function page(Store $store, string $slug): ?array
    {
        return $this->cache->remember($store->id, 'page:'.$slug, function () use ($slug) {
            $page = $this->publishedPages()->firstWhere('slug', $slug);

            return $page === null ? null : [
                'page' => $this->presenter->page($page),
                'seo' => $this->presenter->seo($this->seo->forContentPage($page)),
            ];
        });
    }

    /**
     * Payment methods a shopper may choose at checkout: only those the
     * store's package includes (the same keys CheckoutService enforces),
     * so a shopper never picks a method checkout will then refuse.
     * Uncached: entitlements have their own cache and follow package
     * changes immediately.
     *
     * @return list<string>
     */
    public function paymentMethods(): array
    {
        $entitlements = app(\App\Domain\Packages\Services\EntitlementService::class);

        // Checkout needs ordering itself (OrderService's own gate).
        if (! $entitlements->hasFeature('orders.basic')) {
            return [];
        }

        return array_values(array_filter(
            ['cod', 'bank_transfer'],
            fn (string $method) => $entitlements->hasFeature(\App\Domain\Cart\Services\CheckoutService::FEATURE_KEY_BY_METHOD[$method]),
        ));
    }

    /** @return list<array<string, mixed>> quick search results (uncached: every keystroke differs) */
    public function suggestions(string $q): array
    {
        $products = $this->catalog()->suggest($q)->map(fn (Product $p) => [
            'type' => 'product',
            ...array_intersect_key($this->presenter->card($p), array_flip(['slug', 'name', 'price', 'image'])),
        ]);
        $needle = mb_strtolower($q);
        $categories = $this->catalog()->visibleCategories()
            ->filter(fn (Category $c) => str_contains(mb_strtolower($c->name), $needle))
            ->take(4)
            ->map(fn (Category $c) => ['type' => 'category', 'slug' => $c->slug, 'name' => $c->name]);

        return [...$categories->values()->all(), ...$products->values()->all()];
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, ContentPage> */
    private function publishedPages(): \Illuminate\Database\Eloquent\Collection
    {
        return ContentPage::query()
            ->where('status', ContentPageStatus::Published->value)
            ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->orderBy('title')
            ->get();
    }


    /** @return list<array<string, mixed>> the home page of a store that has not published a theme yet */
    private function defaultSections(Store $store): array
    {
        return [
            ['type' => SectionType::Hero->value, 'config' => ['heading' => $store->name]],
            ['type' => SectionType::FeaturedCategories->value, 'config' => ['limit' => 6]],
            ['type' => SectionType::FeaturedProducts->value, 'config' => ['limit' => 8]],
        ];
    }
    /**
     * Phase B38 (LOC-002, Module 35 §4.3): a theme section's texts in the
     * visitor's language — each text, and each text of a list entry, falls
     * back to the original when it has no translation. Numbers, links and
     * icons are always the original's.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function localized(array $config): array
    {
        $translation = $config['translations'][app(StorefrontLocale::class)->current()] ?? [];
        unset($config['translations']);
        foreach ($translation as $key => $value) {
            if ($key === 'items' && is_array($value) && is_array($config['items'] ?? null)) {
                foreach ($config['items'] as $i => $item) {
                    $config['items'][$i] = [...$item, ...array_filter($value[$i] ?? [], fn ($v) => is_string($v) && $v !== '')];
                }
            } elseif (is_string($value) && $value !== '') {
                $config[$key] = $value;
            }
        }

        return $config;
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
