<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Services;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Collection as CatalogCollection;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\CategoryStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductStatus;
use App\Domain\Catalog\Models\ProductVisibility;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Module 05 — every public catalog query, in one place. Runs under the
 * resolved store's tenant scope; nothing here accepts a store id.
 *
 * Visibility (Module 06 §28), applied consistently:
 *  - browsing lists `public` and `catalog_only` products;
 *  - search lists `public` and `search_only` products;
 *  - a product page opens for any of the three;
 *  - only `public` products can be bought (the cart's own rule).
 * Only `active` products are ever shown.
 *
 * Prices and stock are computed in SQL so filters, sorting and
 * pagination stay correct: a product's price is its cheapest active
 * variant's effective price (sale price when lower), or its own when it
 * has no variants; stock comes from the default warehouse, and a
 * product without an inventory record is untracked (treated as in
 * stock), matching CartService.
 */
final class StorefrontCatalog
{
    /** Phase B43: featured (merchandising order), best selling and relevance (search) join the list. */
    public const SORTS = ['newest', 'featured', 'best_selling', 'price_asc', 'price_desc', 'name', 'relevance'];

    /** Phase B39: a collection page may also follow its own order, or best sellers. */
    public const COLLECTION_SORTS = [...self::SORTS, 'manual'];

    public const BROWSE_VISIBILITY = [ProductVisibility::Public, ProductVisibility::CatalogOnly];

    public const SEARCH_VISIBILITY = [ProductVisibility::Public, ProductVisibility::SearchOnly];

    public const CATEGORY_VISIBILITY = ['public', 'navigation_only', 'search_only'];

    private const HAS_VARIANTS = "EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = products.id AND v.deleted_at IS NULL AND v.status = 'active')";

    private ?int $defaultWarehouseId = null;

    private bool $warehouseResolved = false;

    /** @var array<string, list<int>> Phase B43: bestseller ids per "count:days" */
    private array $bestsellers = [];

    /** @var ?Collection<int, Category> */
    private ?Collection $categories = null;

    /**
     * @param array{q?: ?string, category?: ?string, brand?: ?string, min_price?: ?int, max_price?: ?int, in_stock?: ?bool, collection?: ?string, tag?: ?string, attributes?: array<int, array<string, mixed>>, default_sort?: ?string, featured_boost?: bool, sort?: ?string, page?: ?int, per_page?: ?int} $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function search(array $filters): LengthAwarePaginator
    {
        $q = trim((string) ($filters['q'] ?? ''));
        [$query, $collection] = $this->filtered($filters);
        $query = $this->withPricing($query)->with(['brand', 'images']);

        $sort = self::effectiveSort($filters, $collection);
        if ($sort === 'manual' && $collection !== null && $collection->type === 'manual') {
            $query->leftJoin('collection_product as cp_order', fn ($j) => $j->on('cp_order.product_id', '=', 'products.id')->where('cp_order.collection_id', '=', $collection->id))
                ->orderBy('cp_order.position')->orderByDesc('products.id');
            $sort = null;
        } elseif ($sort === 'best_selling') {
            $query->leftJoinSub($this->unitsSold(), 'sold_rank', 'sold_rank.product_id', '=', 'products.id')->orderByDesc('sold_rank.units')->orderByDesc('products.id');
            $sort = null;
        }

        match ($sort ?? 'none') {
            'none' => null,
            'price_asc' => $query->orderBy('price_from_minor')->orderBy('products.id'),
            'price_desc' => $query->orderByDesc('price_from_minor')->orderByDesc('products.id'),
            'name' => $query->orderBy('products.name')->orderBy('products.id'),
            'featured' => $this->merchandisingOrder($query),
            // Phase B43 (Module 06 §37): name matches first; then, when the store boosts them, featured
            // and higher-priority products; then the newest. Deterministic: the id breaks every tie.
            'relevance' => $q === '' ? $this->newestOrder($query) : (function () use ($query, $q, $filters) {
                $query->orderByRaw('products.name LIKE ? DESC', [addcslashes($q, '%_\\').'%']);
                if (! empty($filters['featured_boost'])) {
                    $query->orderByDesc('products.is_featured')->orderByDesc('products.sort_priority');
                }
                $query->orderByDesc('products.id');
            })(),
            default => $this->newestOrder($query),
        };

        $perPage = max(1, min((int) ($filters['per_page'] ?? config('storefront.per_page')), (int) config('storefront.max_per_page')));

        return $query->paginate($perPage, ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
    }

    /**
     * Phase B43 (Module 05 §19, Module 07 §17): the order a listing uses — the
     * shopper's choice; else a collection's own; else relevance for a search;
     * else the default the page was given (the category's, or the store's);
     * else newest.
     *
     * @param array<string, mixed> $filters
     */
    public static function effectiveSort(array $filters, ?CatalogCollection $collection = null): string
    {
        return (string) ($filters['sort']
            ?? ($collection !== null ? $collection->sort : null)
            ?? (trim((string) ($filters['q'] ?? '')) !== '' ? 'relevance' : null)
            ?? $filters['default_sort']
            ?? 'newest');
    }

    /**
     * Phase B43 (Module 06 §37, §93): the store's merchandising order —
     * featured first, then higher sort priority, then newest, then id. The
     * same products always come in the same order.
     *
     * @param Builder<Product> $query
     */
    private function merchandisingOrder(Builder $query): void
    {
        $query->orderByDesc('products.is_featured')->orderByDesc('products.sort_priority');
        $this->newestOrder($query);
    }

    /** @param Builder<Product> $query */
    private function newestOrder(Builder $query): void
    {
        $query->orderByRaw('COALESCE(products.published_at, products.created_at) DESC')->orderByDesc('products.id');
    }

    /**
     * Phase B43 (Module 06 §36): the products that sold most units in the
     * last days, on orders that count — the store's "bestsellers". Memoized
     * for the request.
     *
     * @return list<int>
     */
    public function bestsellerIds(int $count, int $days): array
    {
        return $this->bestsellers["{$count}:{$days}"] ??= \App\Domain\Orders\Models\OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotIn('orders.status', ['draft', 'cancelled', 'failed'])
            ->where('orders.created_at', '>=', now()->subDays($days))
            ->whereNotNull('order_items.product_id')
            ->groupBy('order_items.product_id')
            ->orderByRaw('SUM(order_items.quantity) DESC')->orderBy('order_items.product_id')
            ->limit($count)
            ->pluck('order_items.product_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Phase B43: units that can be bought now in the default warehouse, per
     * product (a product with variants: its active variants together). A
     * product without stock records is untracked and not in the answer.
     *
     * @param list<int> $productIds
     * @return array<int, int>
     */
    public function stockUnits(array $productIds): array
    {
        $warehouseId = $this->defaultWarehouseId();
        if ($productIds === [] || $warehouseId === null) {
            return [];
        }
        $available = 'GREATEST(CAST(i.on_hand AS SIGNED) - CAST(i.reserved AS SIGNED), 0)';
        $simple = DB::table('inventories as i')->where('i.warehouse_id', $warehouseId)->whereNull('i.product_variant_id')->whereIn('i.product_id', $productIds)
            ->groupBy('i.product_id')->selectRaw("i.product_id AS pid, SUM({$available}) AS units")->pluck('units', 'pid');
        $variants = DB::table('inventories as i')->join('product_variants as v', 'v.id', '=', 'i.product_variant_id')
            ->where('i.warehouse_id', $warehouseId)->whereNull('v.deleted_at')->where('v.status', 'active')->whereIn('v.product_id', $productIds)
            ->groupBy('v.product_id')->selectRaw("v.product_id AS pid, SUM({$available}) AS units")->pluck('units', 'pid');
        $out = [];
        foreach ([$simple, $variants] as $set) {
            foreach ($set as $pid => $units) {
                $out[(int) $pid] = ($out[(int) $pid] ?? 0) + (int) $units;
            }
        }

        return $out;
    }

    /**
     * Phase B43 (Module 06 §37 "recommendations"): browsable products of a
     * category in the merchandising order, for "You may also like".
     *
     * @return Collection<int, Product>
     */
    public function recommended(int $limit, int $exceptId, ?int $categoryId): Collection
    {
        $query = $this->withPricing($this->visible(self::BROWSE_VISIBILITY))
            ->with(['brand', 'images'])
            ->where('products.id', '!=', $exceptId)
            ->when($categoryId !== null, fn ($q) => $q->where('products.primary_category_id', $categoryId));
        $this->merchandisingOrder($query);

        return $query->limit($limit)->get();
    }

    /**
     * Phase B41 (Module 07 §48): for each filter attribute, how many listed
     * products have each value — counted with every other active filter
     * applied, but not the attribute's own (so choosing "8 GB" still shows
     * how many "16 GB" there are). Numbers report their range; yes/no the
     * count of "yes".
     *
     * @param array<string, mixed> $filters as for search()
     * @param list<\App\Domain\Catalog\Models\Attribute> $attributes
     * @return array<int, array{counts?: array<int, int>, min?: ?float, max?: ?float, yes?: int}>
     */
    public function attributeFacets(array $filters, array $attributes): array
    {
        $out = [];
        foreach ($attributes as $attribute) {
            [$query] = $this->filtered($filters, skipAttribute: $attribute->id);
            $ids = $query->select('products.id');
            $values = DB::table('product_attribute_values as f')
                ->joinSub($ids, 'p', 'p.id', '=', 'f.product_id')
                ->where('f.attribute_id', $attribute->id);
            $out[$attribute->id] = match (true) {
                $attribute->type->hasValues() => ['counts' => $values->groupBy('f.attribute_value_id')
                    ->selectRaw('f.attribute_value_id AS v, COUNT(DISTINCT f.product_id) AS c')->pluck('c', 'v')
                    ->mapWithKeys(fn ($c, $v) => [(int) $v => (int) $c])->all()],
                $attribute->type === \App\Domain\Catalog\Models\AttributeType::Numeric => (function () use ($values) {
                    $range = $values->selectRaw('MIN(f.number_value) AS lo, MAX(f.number_value) AS hi')->first();

                    return ['min' => $range?->lo !== null ? (float) $range->lo : null, 'max' => $range?->hi !== null ? (float) $range->hi : null];
                })(),
                default => ['yes' => (int) $values->where('f.bool_value', true)->distinct()->count('f.product_id')],
            };
        }

        return $out;
    }

    /**
     * The visible products that match the filters (no columns chosen, no
     * order) and the live collection the filters name, if any.
     *
     * @param array<string, mixed> $filters
     * @return array{0: Builder<Product>, 1: ?CatalogCollection}
     */
    private function filtered(array $filters, ?int $skipAttribute = null): array
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $query = $this->visible($q === '' ? self::BROWSE_VISIBILITY : self::SEARCH_VISIBILITY);

        if ($q !== '') {
            foreach (array_slice(preg_split('/\s+/', $q) ?: [], 0, 5) as $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn (Builder $w) => $w->where('products.name', 'like', $like)
                    ->orWhere('products.sku', 'like', $like)
                    ->orWhere('products.short_description', 'like', $like));
            }
        }

        if (! empty($filters['category'])) {
            $category = $this->category($filters['category']);
            // An unknown or hidden category lists nothing, never everything.
            $ids = $category !== null ? $this->descendantIds($category) : [0];
            $query->where(fn (Builder $w) => $w->whereIn('products.primary_category_id', $ids)
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('product_category')
                    ->whereColumn('product_category.product_id', 'products.id')->whereIn('product_category.category_id', $ids)));
        }

        if (! empty($filters['brand'])) {
            $query->whereIn('products.brand_id', Brand::query()->where('slug', $filters['brand'])->select('id'));
        }

        // Phase B39 (Module 06 §34–35): a live collection, a tag.
        $collection = null;
        if (! empty($filters['collection'])) {
            $collection = CatalogCollection::query()->live()->where('slug', $filters['collection'])->first();
            // An unknown, hidden or out-of-schedule collection lists nothing, never everything.
            $collection === null ? $query->whereRaw('1 = 0') : $this->applyCollection($query, $collection);
        }
        if (! empty($filters['tag'])) {
            $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('product_tag')->join('tags', 'tags.id', '=', 'product_tag.tag_id')
                ->whereColumn('product_tag.product_id', 'products.id')->where('tags.slug', $filters['tag']));
        }

        if (isset($filters['min_price'])) {
            $query->whereRaw($this->priceFromSql().' >= ?', [(int) $filters['min_price']]);
        }

        if (isset($filters['max_price'])) {
            $query->whereRaw($this->priceFromSql().' <= ?', [(int) $filters['max_price']]);
        }

        if (! empty($filters['in_stock'])) {
            $query->whereRaw($this->inStockSql().' = 1', $this->inStockBindings());
        }

        // Phase B41 (Module 07 §47–49): attribute filters, already checked against the category's filters.
        foreach ((array) ($filters['attributes'] ?? []) as $attributeId => $wanted) {
            if ((int) $attributeId === $skipAttribute) {
                continue;
            }
            $query->whereExists(function ($sub) use ($attributeId, $wanted) {
                $sub->selectRaw('1')->from('product_attribute_values as pav')
                    ->whereColumn('pav.product_id', 'products.id')->where('pav.attribute_id', (int) $attributeId);
                if (isset($wanted['values'])) {
                    $sub->whereIn('pav.attribute_value_id', array_map('intval', (array) $wanted['values']));
                } elseif (isset($wanted['yes'])) {
                    $sub->where('pav.bool_value', true);
                } else {
                    if (isset($wanted['min'])) {
                        $sub->where('pav.number_value', '>=', (float) $wanted['min']);
                    }
                    if (isset($wanted['max'])) {
                        $sub->where('pav.number_value', '<=', (float) $wanted['max']);
                    }
                }
            });
        }

        return [$query, $collection];
    }

    /**
     * Phase B39: the products of a collection — picked by hand, or matching
     * its rules (CollectionRules: a fixed set of conditions, never a column
     * from the request).
     *
     * @param Builder<Product> $query
     */
    public function applyCollection(Builder $query, CatalogCollection $collection): void
    {
        if ($collection->type === 'manual') {
            $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('collection_product')
                ->whereColumn('collection_product.product_id', 'products.id')->where('collection_product.collection_id', $collection->id));

            return;
        }
        $rules = $collection->rules ?? [];
        if ($rules === []) {
            $query->whereRaw('1 = 0');

            return;
        }
        $method = $collection->match === 'any' ? 'orWhere' : 'where';
        $query->where(function (Builder $w) use ($rules, $method) {
            foreach ($rules as $rule) {
                $w->{$method}(fn (Builder $c) => $this->applyRule($c, (string) $rule['field'], $rule['value']));
            }
        });
    }

    /**
     * Whether one product is in a collection now — for promotions that target
     * a collection (Module 14 §9). Visibility does not matter here: a product
     * in the cart is checked by the cart itself.
     */
    public function inCollection(CatalogCollection $collection, int $productId): bool
    {
        if (! $collection->isLive()) {
            return false;
        }
        $query = $this->withPricing(Product::query()->where('products.id', $productId));
        $this->applyCollection($query, $collection);

        return $query->exists();
    }

    /** How many products a collection would show now, live or not (for the admin preview). */
    public function collectionCount(CatalogCollection $collection): int
    {
        $query = $this->withPricing($this->visible(self::BROWSE_VISIBILITY));
        $this->applyCollection($query, $collection);

        return $query->toBase()->getCountForPagination();
    }

    /** A collection page: live (active, visible, inside its schedule) only. */
    public function liveCollection(string $slug): ?CatalogCollection
    {
        return CatalogCollection::query()->live()->where('slug', $slug)->first();
    }

    /** @return Collection<int, CatalogCollection> */
    public function liveCollections(): Collection
    {
        return CatalogCollection::query()->live()->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * Phase B39 (Module 06 §36): browsable products marked featured, newest first.
     *
     * @return Collection<int, Product>
     */
    public function featured(int $limit): Collection
    {
        $query = $this->withPricing($this->visible(self::BROWSE_VISIBILITY))
            ->where('products.is_featured', true)
            ->with(['brand', 'images']);
        $this->merchandisingOrder($query); // Phase B43: sort priority decides among featured products

        return $query->limit($limit)->get();
    }

    /**
     * Phase B39 (Module 06 §38): the products shown with one product, of the
     * given relation types, in their set order — browsable ones only.
     *
     * @param list<string> $types
     * @return Collection<int, Product>
     */
    public function relatedTo(int $productId, array $types, int $limit): Collection
    {
        return $this->withPricing($this->visible(self::BROWSE_VISIBILITY))
            ->join('product_relations as pr', fn ($j) => $j->on('pr.related_product_id', '=', 'products.id')
                ->where('pr.product_id', '=', $productId)->whereIn('pr.type', $types))
            ->where('products.id', '!=', $productId)
            ->with(['brand', 'images'])
            ->orderBy('pr.position')->orderBy('pr.id')
            ->limit($limit)
            ->get()
            ->unique('id')->values();
    }

    /** @param Builder<Product> $query */
    private function applyRule(Builder $query, string $field, mixed $value): void
    {
        match ($field) {
            'category' => $query->where(function (Builder $w) use ($value) {
                $ids = $this->withDescendants((array) $value);
                $w->whereIn('products.primary_category_id', $ids)
                    ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('product_category')
                        ->whereColumn('product_category.product_id', 'products.id')->whereIn('product_category.category_id', $ids));
            }),
            'brand' => $query->whereIn('products.brand_id', (array) $value),
            'tag' => $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('product_tag')
                ->whereColumn('product_tag.product_id', 'products.id')->whereIn('product_tag.tag_id', (array) $value)),
            'price_min' => $query->whereRaw($this->priceFromSql().' >= ?', [(int) $value]),
            'price_max' => $query->whereRaw($this->priceFromSql().' <= ?', [(int) $value]),
            'on_sale' => $query->whereRaw($this->onSaleSql().' = 1'),
            'in_stock' => $query->whereRaw($this->inStockSql().' = 1', $this->inStockBindings()),
            'featured' => $query->where('products.is_featured', true),
            'new_within_days' => $query->whereRaw('COALESCE(products.published_at, products.created_at) >= ?', [now()->subDays((int) $value)]),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function withDescendants(array $ids): array
    {
        $all = $ids;
        foreach (Category::query()->whereIn('id', $ids)->get() as $category) {
            $all = [...$all, ...$this->descendantIds($category)];
        }

        return array_values(array_unique($all));
    }

    /** Units sold per product on this store's orders that count (not draft, cancelled or failed). */
    private function unitsSold(): \Illuminate\Database\Eloquent\Builder
    {
        return \App\Domain\Orders\Models\OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotIn('orders.status', ['draft', 'cancelled', 'failed'])
            ->whereNotNull('order_items.product_id')
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) AS units');
    }

    /** Owner decision 15: all units of one product sold on orders that count (the same rule as best sellers). */
    public function unitsSoldOf(int $productId): int
    {
        return (int) $this->unitsSold()->where('order_items.product_id', $productId)->value('units');
    }

    /** A product page: any searchable or browsable active product. */
    public function product(string $slug): ?Product
    {
        return $this->withPricing($this->visible([...self::BROWSE_VISIBILITY, ProductVisibility::SearchOnly]))
            ->where('products.slug', $slug)
            ->with(['brand', 'images', 'primaryCategory', 'variants' => fn ($q) => $q->where('status', 'active')->orderBy('id')])
            ->first();
    }

    /** @return Collection<int, Product> */
    public function newest(int $limit, ?int $exceptId = null, ?int $categoryId = null): Collection
    {
        return $this->withPricing($this->visible(self::BROWSE_VISIBILITY))
            ->with(['brand', 'images'])
            ->when($exceptId !== null, fn ($q) => $q->where('products.id', '!=', $exceptId))
            ->when($categoryId !== null, fn ($q) => $q->where('products.primary_category_id', $categoryId))
            ->orderByRaw('COALESCE(products.published_at, products.created_at) DESC')->orderByDesc('products.id')
            ->limit($limit)
            ->get();
    }

    /**
     * Module 17 §25 "Best sellers" (Phase B36): browsable products by units
     * sold on this store's orders (not cancelled, failed or draft). A store
     * with no sales yet gets none — the section is then not shown, rather
     * than calling other products best sellers.
     *
     * @return Collection<int, Product>
     */
    public function bestSellers(int $limit): Collection
    {
        return $this->withPricing($this->visible(self::BROWSE_VISIBILITY))
            ->joinSub($this->unitsSold(), 'sold', 'sold.product_id', '=', 'products.id')
            ->with(['brand', 'images'])
            ->orderByDesc('sold.units')->orderByDesc('products.id')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, Product> Module 17 §25 (Phase B36): browsable products on sale, newest first */
    public function onSale(int $limit): Collection
    {
        return $this->withPricing($this->visible(self::BROWSE_VISIBILITY))
            ->whereRaw($this->onSaleSql().' = 1')
            ->with(['brand', 'images'])
            ->orderByRaw('COALESCE(products.published_at, products.created_at) DESC')->orderByDesc('products.id')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, Product> products whose name (or a word of it) starts with $q */
    public function suggest(string $q, int $limit = 6): Collection
    {
        $like = addcslashes($q, '%_\\').'%';

        return $this->withPricing($this->visible(self::SEARCH_VISIBILITY))
            ->with('images')
            ->where(fn (Builder $w) => $w->where('products.name', 'like', $like)->orWhere('products.name', 'like', '% '.$like))
            ->orderBy('products.name')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, Category> every category shoppers may see */
    public function visibleCategories(): Collection
    {
        return $this->categories ??= Category::query()
            ->where('status', CategoryStatus::Active->value)
            ->whereIn('visibility', self::CATEGORY_VISIBILITY)
            ->orderBy('sort_order')->orderBy('name')
            ->get();
    }

    public function category(string $slug): ?Category
    {
        return $this->visibleCategories()->firstWhere('slug', $slug);
    }

    /**
     * The category and every visible category below it. A hidden child
     * hides its whole subtree.
     *
     * @return list<int>
     */
    public function descendantIds(Category $category): array
    {
        $children = $this->visibleCategories()->groupBy('parent_id');
        $ids = [];
        $stack = [$category->id];

        while ($stack !== []) {
            $id = array_pop($stack);
            $ids[] = $id;

            foreach ($children->get($id, collect()) as $child) {
                $stack[] = $child->id;
            }
        }

        return $ids;
    }

    /**
     * Browsable products per category, each category counting its whole
     * visible subtree and a product only once even when it sits in
     * several of those categories.
     *
     * @return array<int, int> category id => product count
     */
    public function productCountsByCategory(): array
    {
        $visibleIds = $this->visible(self::BROWSE_VISIBILITY)->select('products.id');
        $direct = [];

        foreach (DB::table('product_category')->whereIn('product_id', $visibleIds)->get(['category_id', 'product_id']) as $row) {
            $direct[(int) $row->category_id][(int) $row->product_id] = true;
        }

        foreach ($this->visible(self::BROWSE_VISIBILITY)->whereNotNull('products.primary_category_id')->get(['products.id', 'products.primary_category_id']) as $product) {
            $direct[(int) $product->primary_category_id][$product->id] = true;
        }

        $counts = [];
        foreach ($this->visibleCategories() as $category) {
            $products = [];
            foreach ($this->descendantIds($category) as $id) {
                $products += $direct[$id] ?? [];
            }
            $counts[$category->id] = count($products);
        }

        return $counts;
    }

    /** @return Collection<int, Brand> brands with at least one browsable product */
    public function brands(): Collection
    {
        return Brand::query()
            ->whereIn('id', $this->visible(self::BROWSE_VISIBILITY)->whereNotNull('products.brand_id')->select('products.brand_id'))
            ->orderBy('name')
            ->get();
    }

    public function brand(string $slug): ?Brand
    {
        return $this->brands()->firstWhere('slug', $slug);
    }

    /** Units available for one variant, or for a product without variants; null = untracked. */
    public function stockLevel(int $productId, ?int $variantId): ?int
    {
        $warehouseId = $this->defaultWarehouseId();

        if ($warehouseId === null) {
            return null;
        }

        $row = Inventory::query()
            ->where('warehouse_id', $warehouseId)
            ->when($variantId !== null,
                fn ($q) => $q->where('product_variant_id', $variantId),
                fn ($q) => $q->where('product_id', $productId)->whereNull('product_variant_id'))
            ->first();

        return $row?->available();
    }

    /**
     * @param list<ProductVisibility> $visibility
     * @return Builder<Product>
     */
    private function visible(array $visibility): Builder
    {
        return Product::query()
            ->where('products.status', ProductStatus::Active->value)
            ->whereIn('products.visibility', array_map(fn (ProductVisibility $v) => $v->value, $visibility));
    }

    /**
     * @param Builder<Product> $query
     * @return Builder<Product>
     */
    private function withPricing(Builder $query): Builder
    {
        return $query->select('products.*')
            ->selectRaw($this->priceFromSql().' AS price_from_minor')
            ->selectRaw($this->priceToSql().' AS price_to_minor')
            ->selectRaw($this->onSaleSql().' AS on_sale')
            ->selectRaw('(CASE WHEN '.self::HAS_VARIANTS.' THEN 1 ELSE 0 END) AS has_variants')
            ->selectRaw($this->inStockSql().' AS in_stock', $this->inStockBindings());
    }

    private static function effective(string $t): string
    {
        return "CASE WHEN {$t}.sale_price_minor IS NOT NULL AND {$t}.price_minor IS NOT NULL AND {$t}.sale_price_minor < {$t}.price_minor THEN {$t}.sale_price_minor ELSE {$t}.price_minor END";
    }

    private function variantAggregate(string $fn): string
    {
        return "(SELECT {$fn}(".self::effective('v').") FROM product_variants v WHERE v.product_id = products.id AND v.deleted_at IS NULL AND v.status = 'active')";
    }

    private function priceFromSql(): string
    {
        return 'COALESCE('.$this->variantAggregate('MIN').', '.self::effective('products').')';
    }

    private function priceToSql(): string
    {
        return 'COALESCE('.$this->variantAggregate('MAX').', '.self::effective('products').')';
    }

    private function onSaleSql(): string
    {
        return "(CASE WHEN EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = products.id AND v.deleted_at IS NULL AND v.status = 'active' AND v.sale_price_minor IS NOT NULL AND v.sale_price_minor < v.price_minor)"
            .' OR (products.sale_price_minor IS NOT NULL AND products.sale_price_minor < products.price_minor) THEN 1 ELSE 0 END)';
    }

    /** 1 when at least one unit can be bought, or stock is untracked. */
    private function inStockSql(): string
    {
        $hasVariants = self::HAS_VARIANTS;
        // on_hand/reserved are UNSIGNED: compare, never subtract (MySQL
        // raises "out of range" on a negative unsigned result).
        $variantInStock = "EXISTS (SELECT 1 FROM product_variants v LEFT JOIN inventories i ON i.product_variant_id = v.id AND i.warehouse_id = ? WHERE v.product_id = products.id AND v.deleted_at IS NULL AND v.status = 'active' AND (i.id IS NULL OR i.on_hand > i.reserved))";
        $simpleInStock = 'NOT EXISTS (SELECT 1 FROM inventories i WHERE i.warehouse_id = ? AND i.product_id = products.id AND i.product_variant_id IS NULL AND i.on_hand <= i.reserved)';

        return "(CASE WHEN {$hasVariants} THEN (CASE WHEN {$variantInStock} THEN 1 ELSE 0 END) ELSE (CASE WHEN {$simpleInStock} THEN 1 ELSE 0 END) END)";
    }

    /** @return list<int> */
    private function inStockBindings(): array
    {
        $warehouseId = $this->defaultWarehouseId() ?? 0; // no default warehouse: no rows, so everything is untracked

        return [$warehouseId, $warehouseId];
    }

    private function defaultWarehouseId(): ?int
    {
        if (! $this->warehouseResolved) {
            $this->defaultWarehouseId = Warehouse::query()->where('is_default', true)->value('id');
            $this->warehouseResolved = true;
        }

        return $this->defaultWarehouseId;
    }
}
