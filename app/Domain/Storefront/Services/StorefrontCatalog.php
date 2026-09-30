<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Services;

use App\Domain\Catalog\Models\Brand;
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
    public const SORTS = ['newest', 'price_asc', 'price_desc', 'name'];

    public const BROWSE_VISIBILITY = [ProductVisibility::Public, ProductVisibility::CatalogOnly];

    public const SEARCH_VISIBILITY = [ProductVisibility::Public, ProductVisibility::SearchOnly];

    public const CATEGORY_VISIBILITY = ['public', 'navigation_only', 'search_only'];

    private const HAS_VARIANTS = "EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = products.id AND v.deleted_at IS NULL AND v.status = 'active')";

    private ?int $defaultWarehouseId = null;

    private bool $warehouseResolved = false;

    /** @var ?Collection<int, Category> */
    private ?Collection $categories = null;

    /**
     * @param array{q?: ?string, category?: ?string, brand?: ?string, min_price?: ?int, max_price?: ?int, in_stock?: ?bool, sort?: ?string, page?: ?int, per_page?: ?int} $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function search(array $filters): LengthAwarePaginator
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $query = $this->withPricing($this->visible($q === '' ? self::BROWSE_VISIBILITY : self::SEARCH_VISIBILITY))
            ->with(['brand', 'images']);

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

        if (isset($filters['min_price'])) {
            $query->whereRaw($this->priceFromSql().' >= ?', [(int) $filters['min_price']]);
        }

        if (isset($filters['max_price'])) {
            $query->whereRaw($this->priceFromSql().' <= ?', [(int) $filters['max_price']]);
        }

        if (! empty($filters['in_stock'])) {
            $query->whereRaw($this->inStockSql().' = 1', $this->inStockBindings());
        }

        match ($filters['sort'] ?? 'newest') {
            'price_asc' => $query->orderBy('price_from_minor')->orderBy('products.id'),
            'price_desc' => $query->orderByDesc('price_from_minor')->orderByDesc('products.id'),
            'name' => $query->orderBy('products.name')->orderBy('products.id'),
            default => $q !== ''
                ? $query->orderByRaw('products.name LIKE ? DESC', [addcslashes($q, '%_\\').'%'])->orderByDesc('products.id')
                : $query->orderByRaw('COALESCE(products.published_at, products.created_at) DESC')->orderByDesc('products.id'),
        };

        $perPage = max(1, min((int) ($filters['per_page'] ?? config('storefront.per_page')), (int) config('storefront.max_per_page')));

        return $query->paginate($perPage, ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
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
