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
    private const CONTENT_SECTIONS = ['hero', 'featured_products', 'featured_categories', 'promotional_banner'];

    public function __construct(
        private readonly StorefrontCatalog $catalog,
        private readonly StorefrontPresenter $presenter,
        private readonly StorefrontCache $cache,
        private readonly ThemeResolver $themes,
        private readonly SeoResolver $seo,
        private readonly StructuredDataService $structuredData,
        private readonly ConfigService $config,
    ) {}

    /** @return array<string, mixed> branding, theme and navigation shared by every page */
    public function shell(Store $store): array
    {
        return $this->cache->remember($store->id, 'shell', function () use ($store) {
            $theme = $this->themes->resolvePublished($store);
            $config = $theme['config'] ?? [];
            $branding = $config['branding'] ?? [];
            $announcement = collect($this->sections($config))->firstWhere('type', SectionType::AnnouncementBar->value);
            $counts = $this->catalog->productCountsByCategory();

            return [
                'store' => [
                    'name' => $store->name,
                    'slug' => $store->slug,
                    'currency' => $this->presenter->currency(),
                    'locale' => $this->config->get('store.default_locale'),
                    'tagline' => $branding['tagline'] ?? null,
                    'logo_url' => $branding['logo_url'] ?? null,
                    'favicon_url' => $branding['favicon_url'] ?? null,
                    'social_links' => $branding['social_links'] ?? (object) [],
                ],
                'theme' => [
                    'tokens' => $config['tokens'] ?? (object) [],
                    'custom_css' => $theme['custom_css'] ?? null,
                ],
                'announcement' => $announcement['config']['message'] ?? null,
                'navigation' => [
                    'categories' => array_values(array_filter(
                        $this->presenter->categoryTree($counts),
                        fn (array $category) => $category['in_menu'],
                    )),
                    'pages' => $this->publishedPages()->map(fn (ContentPage $page) => ['slug' => $page->slug, 'title' => $page->title])->values()->all(),
                ],
            ];
        });
    }

    /** @return array<string, mixed> */
    public function home(Store $store): array
    {
        return $this->cache->remember($store->id, 'home', function () use ($store) {
            $theme = $this->themes->resolvePublished($store);
            $sections = $this->sections($theme['config'] ?? []);
            // Every store starts on the default theme, which has only a
            // header and footer: until the owner adds content sections,
            // the home page shows sensible defaults instead of nothing.
            if (! collect($sections)->contains(fn (array $s) => in_array($s['type'], self::CONTENT_SECTIONS, true))) {
                $sections = [...$sections, ...$this->defaultSections($store)];
            }
            $counts = $this->catalog->productCountsByCategory();

            $resolved = [];
            foreach ($sections as $section) {
                $config = $section['config'] ?? [];
                $resolved[] = match ($section['type']) {
                    SectionType::Hero->value, SectionType::PromotionalBanner->value => ['type' => $section['type'], ...$config],
                    SectionType::FeaturedProducts->value => [
                        'type' => $section['type'],
                        'heading' => $config['heading'] ?? 'New arrivals',
                        'products' => $this->catalog->newest((int) ($config['limit'] ?? 8))->map(fn (Product $p) => $this->presenter->card($p))->all(),
                    ],
                    SectionType::FeaturedCategories->value => [
                        'type' => $section['type'],
                        'heading' => $config['heading'] ?? 'Shop by category',
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
        });
    }

    /**
     * @param array<string, mixed> $filters validated listing filters
     * @return array<string, mixed>
     */
    public function listing(Store $store, array $filters): array
    {
        ksort($filters);

        return $this->cache->remember($store->id, 'listing:'.md5((string) json_encode($filters)), function () use ($filters) {
            $page = $this->catalog->search($filters);

            return [
                'products' => collect($page->items())->map(fn (Product $p) => $this->presenter->card($p))->all(),
                'pagination' => [
                    'page' => $page->currentPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'last_page' => $page->lastPage(),
                ],
            ];
        });
    }

    /** @return array<string, mixed> category tree (with counts) and brands for filter menus */
    public function facets(Store $store): array
    {
        return $this->cache->remember($store->id, 'facets', fn () => [
            'categories' => $this->presenter->categoryTree($this->catalog->productCountsByCategory()),
            'brands' => $this->catalog->brands()->map(fn (Brand $b) => $this->presenter->brand($b))->values()->all(),
        ]);
    }

    /** @return ?array<string, mixed> */
    public function product(Store $store, string $slug): ?array
    {
        return $this->cache->remember($store->id, 'product:'.$slug, function () use ($slug) {
            $product = $this->catalog->product($slug);

            if ($product === null) {
                return null;
            }

            $detail = $this->presenter->detail($product);
            $breadcrumbs = collect($detail['breadcrumbs'])->map(fn (array $crumb) => [
                'name' => $crumb['name'],
                'url' => $this->seo->forCategory(Category::query()->where('slug', $crumb['slug'])->firstOrFail())->canonicalUrl,
            ])->all();

            return [
                'product' => $detail,
                'related' => $this->catalog->newest(4, $product->id, $product->primary_category_id)
                    ->map(fn (Product $p) => $this->presenter->card($p))->all(),
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
            $category = $this->catalog->category($slug);

            if ($category === null) {
                return null;
            }

            $counts = $this->catalog->productCountsByCategory();
            $children = $this->catalog->visibleCategories()->where('parent_id', $category->id);

            return [
                'category' => [
                    ...$this->presenter->category($category, $counts[$category->id] ?? 0),
                    'children' => $children->map(fn (Category $c) => $this->presenter->category($c, $counts[$c->id] ?? 0))->values()->all(),
                ],
                'seo' => $this->presenter->seo($this->seo->forCategory($category)),
            ];
        });
    }

    /** @return ?array<string, mixed> */
    public function brand(Store $store, string $slug): ?array
    {
        return $this->cache->remember($store->id, 'brand:'.$slug, function () use ($slug) {
            $brand = $this->catalog->brand($slug);

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
        $products = $this->catalog->suggest($q)->map(fn (Product $p) => [
            'type' => 'product',
            ...array_intersect_key($this->presenter->card($p), array_flip(['slug', 'name', 'price', 'image'])),
        ]);
        $needle = mb_strtolower($q);
        $categories = $this->catalog->visibleCategories()
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

    /**
     * Visible theme sections in their configured order.
     *
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    private function sections(array $config): array
    {
        return collect($config['sections'] ?? [])
            ->filter(fn (array $section) => $section['is_visible'] ?? true)
            ->sortBy('position')
            ->values()->all();
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
}
