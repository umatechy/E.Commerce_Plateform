<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\CatalogImportException;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Collection;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductStatus;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Policies\CollectionPolicy;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Exceptions\UsageLimitExceededException;
use App\Domain\Packages\Services\EntitlementService;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Settings\Services\Currencies;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Phase B40 — Module 06 §48–50, §53: product import from a CSV file
 * (a spreadsheet saved as CSV), staged: upload → parse → validate →
 * preview → confirm → process → report.
 *
 * - The file is read once and never stored; the checked rows wait
 *   encrypted in the cache for 30 minutes, for this store and this staff
 *   member only. Nothing is written before the confirmation.
 * - Matching (§50): a row with an `id` updates that product; otherwise a
 *   row whose `sku` is a product's SKU updates it; otherwise a product is
 *   created. Importing the same file again therefore updates, never
 *   duplicates. Variants match by their own SKU.
 * - On an update an empty cell keeps the current value.
 * - Variants are rows with `parent_sku` (or `parent_id`): the parent is a
 *   product of the file or of the store.
 * - Brands and categories are matched by name (a category may be a path,
 *   "Clothing > Men"); missing ones are created and listed in the preview.
 *   Collections must exist and be hand-picked; tags are created by name.
 * - Every row is processed in its own transaction: one bad row never
 *   undoes the others (§49). Permissions are checked per row; the
 *   package's product limit per new product (§53) — rows past the limit
 *   are reported, not created.
 * - Pictures are not fetched from addresses in the file (no requests to
 *   other servers); `image_urls` is an export-only column.
 */
final class ProductImport
{
    public const MAX_ROWS = 2000;
    public const MAX_KILOBYTES = 4096;
    private const TTL_MINUTES = 30;
    private const PREVIEW_ROWS = 300;

    public const COLUMNS = [
        'id', 'sku', 'name', 'type', 'status', 'visibility', 'short_description', 'description',
        'price', 'sale_price', 'cost_price', 'currency', 'brand', 'category', 'categories', 'tags', 'collections', 'featured',
        'parent_sku', 'parent_id', 'options', 'barcode', 'image_urls',
        'attributes', // Phase B42: "key: value; key: value, value" (specifications)
        'sort_priority', 'badges', // Phase B43: merchandising order; the store's own badges by label
    ];

    private const TYPES = ['simple', 'variable', 'digital', 'service', 'bundle'];
    private const VISIBILITIES = ['public', 'catalog_only', 'search_only', 'hidden', 'private', 'scheduled'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
        private readonly ProductTagService $tags,
        private readonly AuditLogger $audit,
        private readonly ConfigService $config,
    ) {}

    /**
     * @return array{id: string, totals: array<string, int>, rows: list<array<string, mixed>>, new_brands: list<string>, new_categories: list<string>, notices: list<string>, columns: list<string>}
     */
    public function preview(UploadedFile $file, User $actor): array
    {
        [$header, $records] = $this->read($file);
        $notices = [];
        $canCost = $this->canSeeCost($actor);
        if (in_array('cost_price', $header, true) && ! $canCost) {
            $notices[] = 'The cost_price column is ignored: your role cannot see cost prices.';
        }
        if (in_array('image_urls', $header, true)) {
            $notices[] = 'The image_urls column is ignored on import. Add pictures on the product page.';
        }
        $canCreate = Gate::forUser($actor)->allows('create', Product::class);
        $canCollections = app(CollectionPolicy::class)->manage($actor);
        $defaultCurrency = (string) $this->config->get('store.default_currency');

        $byPublicId = Product::query()->whereIn('public_id', array_filter(array_column($records, 'id')))->get()->keyBy('public_id');
        $skus = array_values(array_filter(array_merge(array_column($records, 'sku'), array_column($records, 'parent_sku')), fn ($s) => $s !== ''));
        $bySku = Product::withTrashed()->whereIn('sku', $skus)->get()->keyBy(fn (Product $p) => mb_strtolower((string) $p->sku));
        $variantsBySku = ProductVariant::withTrashed()->whereIn('sku', $skus)->get()->keyBy(fn (ProductVariant $v) => mb_strtolower((string) $v->sku));
        $collections = Collection::query()->get()->keyBy(fn (Collection $c) => mb_strtolower($c->name));
        $brands = Brand::query()->pluck('name')->map(fn ($n) => mb_strtolower((string) $n))->flip();
        $categoryPaths = $this->categoryPaths();
        $badgeIndex = \App\Domain\Catalog\Models\Badge::query()->pluck('id', 'label')->mapWithKeys(fn ($id, $label) => [mb_strtolower((string) $label) => (int) $id]);
        $attributeIndex = \App\Domain\Catalog\Models\Attribute::query()->with('values')->get()
            ->keyBy(fn (\App\Domain\Catalog\Models\Attribute $a) => mb_strtolower($a->key));

        $plan = [];
        $report = [];
        $seenProducts = [];   // id or sku => row
        $seenVariants = [];   // variant sku => row
        $fileSkus = [];       // product skus of valid product rows in this file
        $newBrands = [];
        $newCategories = [];
        $totals = ['rows' => count($records), 'create' => 0, 'update' => 0, 'invalid' => 0, 'variants' => 0];
        $newCounting = 0;

        foreach ($records as $index => $r) {
            $line = $index + 2;
            $messages = [];
            $isVariant = ($r['parent_sku'] ?? '') !== '' || ($r['parent_id'] ?? '') !== '';
            $currency = strtoupper(($r['currency'] ?? '') !== '' ? $r['currency'] : $defaultCurrency);
            if (! in_array($currency, Currencies::supported(), true)) {
                $messages[] = "The currency \"{$currency}\" is not one the platform offers.";
                $currency = $defaultCurrency;
            }
            $price = $this->money($r['price'] ?? '', $currency, 'price', $messages);
            $sale = $this->money($r['sale_price'] ?? '', $currency, 'sale_price', $messages);
            $cost = $canCost ? $this->money($r['cost_price'] ?? '', $currency, 'cost_price', $messages) : null;

            if ($isVariant) {
                $row = $this->checkVariant($r, $line, $messages, $byPublicId, $bySku, $variantsBySku, $fileSkus, $seenVariants, $actor);
                $row += ['price_minor' => $price, 'sale_price_minor' => $sale, 'cost_price_minor' => $cost];
                $this->checkSale($row, $messages, $row['action'] === 'update' ? ProductVariant::query()->find($row['variant_id'] ?? 0)?->price_minor : null);
                $label = ($r['sku'] ?? '').' ('.($r['options'] ?? '').')';
            } else {
                $row = $this->checkProduct($r, $line, $messages, $byPublicId, $bySku, $seenProducts, $actor, $canCreate);
                $row += [
                    'price_minor' => $price, 'sale_price_minor' => $sale, 'cost_price_minor' => $cost, 'currency' => ($r['currency'] ?? '') !== '' ? $currency : null,
                    'brand' => $this->text($r['brand'] ?? '', 255, 'brand', $messages),
                    'category' => $this->categoryPath($r['category'] ?? '', $messages),
                    'categories' => array_values(array_filter(array_map(fn ($p) => $this->categoryPath($p, $messages), $this->list($r['categories'] ?? '')))),
                    'tags' => $this->list($r['tags'] ?? ''),
                    'collections' => $this->list($r['collections'] ?? ''),
                    'featured' => $this->flag($r['featured'] ?? '', $messages),
                    'attributes' => $this->attributesCell($r['attributes'] ?? '', $attributeIndex, $messages),
                    'sort_priority' => $this->sortPriority($r['sort_priority'] ?? '', $messages),
                    'badges' => $this->badgesCell($r['badges'] ?? '', $badgeIndex, $messages),
                ];
                $this->checkSale($row, $messages, $row['action'] === 'update' ? Product::query()->find($row['product_id'] ?? 0)?->price_minor : null);
                if (count($row['tags']) > ProductTagService::MAX_PER_PRODUCT || array_filter($row['tags'], fn ($t) => mb_strlen($t) > 60) !== []) {
                    $messages[] = 'At most '.ProductTagService::MAX_PER_PRODUCT.' tags, each up to 60 characters.';
                }
                if ($row['collections'] !== [] && ! $canCollections) {
                    $messages[] = 'Your role cannot change collections; leave the collections column empty.';
                }
                foreach ($row['collections'] as $name) {
                    $collection = $collections->get(mb_strtolower($name));
                    if ($collection === null || $collection->type !== 'manual') {
                        $messages[] = "There is no hand-picked collection called \"{$name}\".";
                    }
                }
                if ($messages === []) {
                    if ($row['brand'] !== null && ! $brands->has(mb_strtolower($row['brand']))) {
                        $newBrands[mb_strtolower($row['brand'])] = $row['brand'];
                    }
                    foreach (array_filter([$row['category'], ...$row['categories']]) as $path) {
                        if (! isset($categoryPaths[mb_strtolower($path)])) {
                            $newCategories[mb_strtolower($path)] = $path;
                        }
                    }
                    if ($row['sku'] !== null) {
                        $fileSkus[mb_strtolower($row['sku'])] = $line;
                    }
                    if ($row['action'] === 'create' && ProductStatus::from($row['status'] ?? 'draft')->countsTowardUsageLimit()) {
                        $newCounting++;
                    }
                }
                $label = (string) ($row['name'] ?? $r['name'] ?? '');
            }

            $status = $messages === [] ? $row['action'] : 'invalid';
            $totals[$status]++;
            if ($status !== 'invalid' && $isVariant) {
                $totals['variants']++;
            }
            if ($status !== 'invalid') {
                $plan[] = $row;
            }
            if (count($report) < self::PREVIEW_ROWS || $status === 'invalid') {
                $report[] = ['row' => $line, 'kind' => $isVariant ? 'variant' : 'product', 'label' => $label, 'sku' => $r['sku'] ?? '', 'status' => $status, 'messages' => $messages];
            }
        }

        $limit = $this->remainingProducts();
        if ($limit !== null && $newCounting > $limit) {
            $notices[] = "Your package allows {$limit} more products; {$newCounting} new ones are in the file. Rows past the limit will not be created.";
        }

        $id = (string) Str::ulid();
        Cache::put($this->cacheKey($id, $actor), Crypt::encryptString((string) json_encode($plan)), now()->addMinutes(self::TTL_MINUTES));

        return [
            'id' => $id,
            'totals' => $totals,
            'rows' => array_slice($report, 0, self::PREVIEW_ROWS + 200),
            'new_brands' => array_values($newBrands),
            'new_categories' => array_values($newCategories),
            'notices' => $notices,
            'columns' => self::COLUMNS,
        ];
    }

    /**
     * Processes a previewed file. Each row in its own transaction.
     *
     * @return array{created: int, updated: int, variants_created: int, variants_updated: int, skipped: list<array{row: int, reason: string}>}
     */
    public function confirm(string $id, User $actor): array
    {
        $payload = Cache::pull($this->cacheKey($id, $actor));
        if (! is_string($payload)) {
            throw new CatalogImportException('This import has expired or was already done. Upload the file again.', 'import_expired', 410);
        }
        /** @var list<array<string, mixed>> $plan */
        $plan = json_decode(Crypt::decryptString($payload), true) ?: [];
        $result = ['created' => 0, 'updated' => 0, 'variants_created' => 0, 'variants_updated' => 0, 'skipped' => []];
        $productIdsBySku = [];

        // Products first, so the variants of new products find their parent.
        foreach ([false, true] as $variants) {
            foreach ($plan as $row) {
                if ((bool) $row['variant'] !== $variants) {
                    continue;
                }
                try {
                    $outcome = DB::transaction(fn () => $variants ? $this->applyVariant($row, $productIdsBySku, $actor) : $this->applyProduct($row, $actor));
                    if (! $variants && isset($row['sku'])) {
                        $productIdsBySku[mb_strtolower((string) $row['sku'])] = $outcome['id'];
                    }
                    $result[$outcome['key']]++;
                } catch (UsageLimitExceededException $e) {
                    $result['skipped'][] = ['row' => (int) $row['row'], 'reason' => "Your package's product limit ({$e->limit}) is reached."];
                } catch (CatalogImportException $e) {
                    $result['skipped'][] = ['row' => (int) $row['row'], 'reason' => $e->getMessage()];
                } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                    $result['skipped'][] = ['row' => (int) $row['row'], 'reason' => 'The SKU was taken by another product since the preview.'];
                } catch (\Illuminate\Validation\ValidationException $e) {
                    // e.g. a specification the category requires is missing (Phase B42); the whole row is undone.
                    $result['skipped'][] = ['row' => (int) $row['row'], 'reason' => (string) collect($e->errors())->flatten()->first()];
                }
            }
        }
        usort($result['skipped'], fn ($a, $b) => $a['row'] <=> $b['row']);

        $this->audit->record('products.imported', [
            'created' => $result['created'], 'updated' => $result['updated'],
            'variants_created' => $result['variants_created'], 'variants_updated' => $result['variants_updated'],
            'skipped' => count($result['skipped']),
        ], actor: $actor);

        return $result;
    }

    /**
     * @param array<string, string> $r
     * @param list<string> $messages
     * @param \Illuminate\Support\Collection<string, Product> $byPublicId
     * @param \Illuminate\Support\Collection<string, Product> $bySku
     * @param array<string, int> $seen
     * @return array<string, mixed>
     */
    private function checkProduct(array $r, int $line, array &$messages, $byPublicId, $bySku, array &$seen, User $actor, bool $canCreate): array
    {
        $publicId = $r['id'] ?? '';
        $sku = $this->text($r['sku'] ?? '', 64, 'sku', $messages);
        $existing = null;
        if ($publicId !== '') {
            $existing = $byPublicId->get($publicId);
            if ($existing === null) {
                $messages[] = 'No product of this store has this id.';
            }
        } elseif ($sku !== null) {
            $match = $bySku->get(mb_strtolower($sku));
            if ($match !== null && $match->trashed()) {
                $messages[] = 'This SKU belongs to a deleted product. Use another SKU.';
            } else {
                $existing = $match;
            }
        }
        if ($existing !== null && $sku !== null && mb_strtolower((string) $existing->sku) !== mb_strtolower($sku)) {
            $taken = $bySku->get(mb_strtolower($sku));
            if ($taken !== null && $taken->id !== $existing->id) {
                $messages[] = 'This SKU is already used by another product.';
            }
        }
        $key = $existing !== null ? 'p:'.$existing->id : ($sku !== null ? 's:'.mb_strtolower($sku) : null);
        if ($key !== null && isset($seen[$key])) {
            $messages[] = "The same product is already on row {$seen[$key]}.";
        } elseif ($key !== null) {
            $seen[$key] = $line;
        }

        $action = $existing !== null ? 'update' : 'create';
        if ($action === 'create' && ! $canCreate) {
            $messages[] = 'Your role cannot create products.';
        }
        if ($existing !== null && ! Gate::forUser($actor)->allows('update', $existing)) {
            $messages[] = 'Your role cannot change this product.';
        }

        $name = $this->text($r['name'] ?? '', 255, 'name', $messages);
        if ($action === 'create' && $name === null) {
            $messages[] = 'Name is missing.';
        }
        $type = ($r['type'] ?? '') !== '' ? mb_strtolower($r['type']) : null;
        if ($type !== null && ! in_array($type, self::TYPES, true)) {
            $messages[] = 'Type must be one of: '.implode(', ', self::TYPES).'.';
        } elseif ($type !== null && $existing !== null && $existing->type->value !== $type) {
            $messages[] = 'The type of an existing product cannot be changed.';
        }
        $status = ($r['status'] ?? '') !== '' ? mb_strtolower($r['status']) : null;
        if ($status !== null && ProductStatus::tryFrom($status) === null) {
            $messages[] = 'Status must be one of: draft, active, scheduled, hidden, archived.';
            $status = null;
        }
        $visibility = ($r['visibility'] ?? '') !== '' ? mb_strtolower($r['visibility']) : null;
        if ($visibility !== null && ! in_array($visibility, self::VISIBILITIES, true)) {
            $messages[] = 'Visibility must be one of: '.implode(', ', self::VISIBILITIES).'.';
        }

        return [
            'variant' => false, 'row' => $line, 'action' => $action, 'product_id' => $existing?->id,
            'sku' => $sku, 'name' => $name, 'type' => $type ?? ($existing === null ? 'simple' : null),
            'status' => $status ?? ($existing === null ? 'draft' : null), 'visibility' => $visibility ?? ($existing === null ? 'public' : null),
            'short_description' => $this->text($r['short_description'] ?? '', 500, 'short_description', $messages),
            'description' => $this->text($r['description'] ?? '', 20000, 'description', $messages),
        ];
    }

    /**
     * @param array<string, string> $r
     * @param list<string> $messages
     * @param \Illuminate\Support\Collection<string, Product> $byPublicId
     * @param \Illuminate\Support\Collection<string, Product> $bySku
     * @param \Illuminate\Support\Collection<string, ProductVariant> $variantsBySku
     * @param array<string, int> $fileSkus
     * @param array<string, int> $seen
     * @return array<string, mixed>
     */
    private function checkVariant(array $r, int $line, array &$messages, $byPublicId, $bySku, $variantsBySku, array $fileSkus, array &$seen, User $actor): array
    {
        $parent = null;
        $parentSku = ($r['parent_sku'] ?? '') !== '' ? $r['parent_sku'] : null;
        if (($r['parent_id'] ?? '') !== '') {
            $parent = $byPublicId->get($r['parent_id']) ?? Product::query()->where('public_id', $r['parent_id'])->first();
            if ($parent === null) {
                $messages[] = 'No product of this store has the parent_id.';
            }
        } elseif ($parentSku !== null) {
            $parent = $bySku->get(mb_strtolower($parentSku));
            if ($parent !== null && $parent->trashed()) {
                $messages[] = 'The parent SKU belongs to a deleted product.';
                $parent = null;
            } elseif ($parent === null && ! isset($fileSkus[mb_strtolower($parentSku)])) {
                $messages[] = "No product with SKU \"{$parentSku}\" in the store or on an earlier row of the file.";
            }
        }
        if ($parent !== null && ! Gate::forUser($actor)->allows('update', $parent)) {
            $messages[] = 'Your role cannot change the parent product.';
        }

        $sku = $this->text($r['sku'] ?? '', 64, 'sku', $messages);
        if ($sku === null) {
            $messages[] = 'A variant row needs its own SKU.';
        }
        $existing = $sku !== null ? $variantsBySku->get(mb_strtolower($sku)) : null;
        if ($existing !== null && ($existing->trashed() || ($parent !== null && $existing->product_id !== $parent->id))) {
            $messages[] = 'This variant SKU is used by another product\'s variant.';
            $existing = null;
        }
        if ($sku !== null && isset($seen[mb_strtolower($sku)])) {
            $messages[] = "The same variant SKU is already on row {$seen[mb_strtolower($sku)]}.";
        } elseif ($sku !== null) {
            $seen[mb_strtolower($sku)] = $line;
        }

        $options = $this->options($r['options'] ?? '', $messages);
        if ($existing === null && $options === null) {
            $messages[] = 'A new variant needs its options, for example "Size: M; Colour: Red".';
        }
        $status = ($r['status'] ?? '') !== '' ? mb_strtolower($r['status']) : null;
        if ($status !== null && ! in_array($status, ['active', 'hidden', 'archived'], true)) {
            $messages[] = 'A variant status is active, hidden or archived.';
        }

        return [
            'variant' => true, 'row' => $line, 'action' => $existing !== null ? 'update' : 'create',
            'variant_id' => $existing?->id, 'parent_id' => $parent?->id, 'parent_sku' => $parentSku,
            'sku' => $sku, 'options' => $options, 'status' => $status ?? ($existing === null ? 'active' : null),
            'barcode' => $this->text($r['barcode'] ?? '', 64, 'barcode', $messages),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, key: string}
     */
    private function applyProduct(array $row, User $actor): array
    {
        $values = array_filter([
            'name' => $row['name'], 'sku' => $row['sku'], 'short_description' => $row['short_description'], 'description' => $row['description'],
            'visibility' => $row['visibility'], 'price_minor' => $row['price_minor'], 'sale_price_minor' => $row['sale_price_minor'],
            'cost_price_minor' => $row['cost_price_minor'], 'currency' => $row['currency'], 'is_featured' => $row['featured'],
            'sort_priority' => $row['sort_priority'] ?? null,
        ], fn ($v) => $v !== null);
        if ($row['brand'] !== null) {
            $values['brand_id'] = $this->brand((string) $row['brand'])->id;
        }
        if ($row['category'] !== null) {
            $values['primary_category_id'] = $this->category((string) $row['category'])->id;
        }

        if ($row['action'] === 'create') {
            $status = ProductStatus::from((string) $row['status']);
            if (! Gate::forUser($actor)->allows('create', Product::class)) {
                throw new CatalogImportException('Your role cannot create products.', 'forbidden', 403);
            }
            if ($status->countsTowardUsageLimit()) {
                $this->entitlements->assertWithinLimit('max_products');
            }
            if ($row['sku'] !== null && Product::withTrashed()->where('sku', $row['sku'])->exists()) {
                throw new CatalogImportException('The SKU was taken by another product since the preview.', 'sku_taken', 409);
            }
            $product = Product::query()->create([
                'currency' => (string) $this->config->get('store.default_currency'),
                ...$values,
                'type' => $row['type'],
                'status' => $status,
                'slug' => Str::slug((string) $row['name']).'-'.Str::lower(Str::random(6)),
                ...($status === ProductStatus::Active ? ['published_at' => now()] : []),
            ]);
            if ($status->countsTowardUsageLimit()) {
                $this->entitlements->recordUsage('max_products');
            }
            $key = 'created';
        } else {
            $product = Product::query()->find($row['product_id']) ?? throw new CatalogImportException('The product was deleted since the preview.', 'gone', 404);
            if (! Gate::forUser($actor)->allows('update', $product)) {
                throw new CatalogImportException('Your role cannot change this product.', 'forbidden', 403);
            }
            if ($row['status'] !== null) {
                $from = $product->status;
                $to = ProductStatus::from((string) $row['status']);
                if (! $from->countsTowardUsageLimit() && $to->countsTowardUsageLimit()) {
                    $this->entitlements->assertWithinLimit('max_products');
                }
                $values['status'] = $to;
                if ($from->countsTowardUsageLimit() && ! $to->countsTowardUsageLimit()) {
                    $this->entitlements->releaseUsage('max_products');
                } elseif (! $from->countsTowardUsageLimit() && $to->countsTowardUsageLimit()) {
                    $this->entitlements->recordUsage('max_products');
                }
            }
            $product->fill($values);
            if ($product->sale_price_minor !== null && $product->price_minor !== null && $product->sale_price_minor >= $product->price_minor) {
                throw new CatalogImportException('The sale price must be lower than the price.', 'sale_price', 422);
            }
            $product->save();
            $key = 'updated';
        }

        $categoryIds = array_map(fn (string $path) => $this->category($path)->id, array_values(array_filter([$row['category'], ...$row['categories']])));
        if ($categoryIds !== []) {
            $product->categories()->syncWithoutDetaching($categoryIds);
        }
        if (($row['badges'] ?? []) !== []) {
            $product->badges()->syncWithoutDetaching($row['badges']); // Phase B43: listed badges are added
        }
        if (($row['attributes'] ?? []) !== []) {
            // Phase B42: the listed attributes are set; the product's other specifications stay.
            $specs = app(ProductSpecifications::class);
            $merged = collect($specs->values($product))->keyBy('attribute_id');
            foreach ($row['attributes'] as $attributeId => $value) {
                $merged[(int) $attributeId] = ['attribute_id' => (int) $attributeId, 'value' => $value];
            }
            $specs->save($product, $merged->values()->all(), $actor);
        }
        if ($row['tags'] !== []) {
            $this->tags->sync($product, $row['tags']);
        }
        foreach ($row['collections'] as $name) {
            $collection = Collection::query()->where('type', 'manual')->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $name)])->first();
            if ($collection !== null && ! $collection->products()->whereKey($product->id)->exists()) {
                $collection->products()->attach($product->id, ['position' => (int) DB::table('collection_product')->where('collection_id', $collection->id)->max('position') + 1]);
                $collection->touch();
            }
        }

        return ['id' => $product->id, 'key' => $key];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int> $productIdsBySku
     * @return array{id: int, key: string}
     */
    private function applyVariant(array $row, array $productIdsBySku, User $actor): array
    {
        $parentId = $row['parent_id'] ?? ($row['parent_sku'] !== null ? ($productIdsBySku[mb_strtolower((string) $row['parent_sku'])] ?? null) : null);
        $parent = $parentId !== null ? Product::query()->find($parentId) : null;
        if ($parent === null) {
            throw new CatalogImportException('The parent product was not imported (see its row).', 'no_parent', 422);
        }
        if (! Gate::forUser($actor)->allows('update', $parent)) {
            throw new CatalogImportException('Your role cannot change the parent product.', 'forbidden', 403);
        }
        $values = array_filter([
            'sku' => $row['sku'], 'barcode' => $row['barcode'], 'status' => $row['status'], 'option_values' => $row['options'],
            'price_minor' => $row['price_minor'], 'sale_price_minor' => $row['sale_price_minor'], 'cost_price_minor' => $row['cost_price_minor'],
        ], fn ($v) => $v !== null);

        if ($row['action'] === 'update') {
            $variant = ProductVariant::query()->where('product_id', $parent->id)->find($row['variant_id']) ?? throw new CatalogImportException('The variant was deleted since the preview.', 'gone', 404);
            $variant->fill($values);
            if ($variant->sale_price_minor !== null && $variant->price_minor !== null && $variant->sale_price_minor >= $variant->price_minor) {
                throw new CatalogImportException('The sale price must be lower than the price.', 'sale_price', 422);
            }
            $variant->save();

            return ['id' => $variant->id, 'key' => 'variants_updated'];
        }
        if (ProductVariant::withTrashed()->where('sku', $row['sku'])->exists()) {
            throw new CatalogImportException('The variant SKU was taken since the preview.', 'sku_taken', 409);
        }
        $variant = $parent->variants()->create($values);
        $parent->touch();

        return ['id' => $variant->id, 'key' => 'variants_created'];
    }

    private function brand(string $name): Brand
    {
        return Brand::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
            ?? Brand::query()->create(['name' => $name, 'slug' => (Str::slug($name) ?: 'brand').'-'.Str::lower(Str::random(6))]);
    }

    /** A category by its path ("Clothing > Men"), created level by level where missing. */
    private function category(string $path): Category
    {
        $parent = null;
        $category = null;
        foreach (array_map('trim', explode('>', $path)) as $name) {
            $category = Category::query()->where('parent_id', $parent?->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
                ?? Category::query()->create([
                    'parent_id' => $parent?->id, 'name' => $name, 'slug' => (Str::slug($name) ?: 'category').'-'.Str::lower(Str::random(6)),
                    'status' => 'active', 'visibility' => 'public',
                ]);
            $parent = $category;
        }

        return $category; // explode() gives at least one level
    }

    /** @return array<string, true> lower-cased "Parent > Child" paths of the store's categories */
    private function categoryPaths(): array
    {
        $all = Category::query()->get(['id', 'parent_id', 'name'])->keyBy('id');
        $paths = [];
        foreach ($all as $category) {
            $names = [];
            for ($c = $category, $depth = 0; $c !== null && $depth < 10; $c = $c->parent_id !== null ? $all->get($c->parent_id) : null, $depth++) {
                array_unshift($names, $c->name);
            }
            $paths[mb_strtolower(implode(' > ', $names))] = true;
        }

        return $paths;
    }

    /** @param list<string> $messages */
    private function categoryPath(string $value, array &$messages): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $parts = array_map('trim', explode('>', $value));
        if (count($parts) > 5 || array_filter($parts, fn ($p) => $p === '' || mb_strlen($p) > 255) !== []) {
            $messages[] = "The category \"{$value}\" is not a valid path (up to 5 levels, as \"Parent > Child\").";

            return null;
        }

        return implode(' > ', $parts);
    }

    /** @param list<string> $messages */
    private function money(string $value, string $currency, string $column, array &$messages): ?int
    {
        $value = str_replace([',', ' '], '', trim($value));
        if ($value === '') {
            return null;
        }
        $digits = Currencies::digits($currency);
        if (preg_match('/^\d{1,12}(\.\d{1,'.max(1, $digits).'})?$/', $value) !== 1 || ($digits === 0 && str_contains($value, '.'))) {
            $messages[] = "{$column} \"{$value}\" is not an amount in {$currency} (for example 2500 or 2500.50).";

            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');

        return (int) $whole * 10 ** $digits + (int) str_pad($fraction, $digits, '0');
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $messages
     */
    private function checkSale(array $row, array &$messages, ?int $currentPrice): void
    {
        $price = $row['price_minor'] ?? $currentPrice;
        if ($row['sale_price_minor'] !== null && $price !== null && $row['sale_price_minor'] >= $price) {
            $messages[] = 'The sale price must be lower than the price.';
        }
    }

    /** @param list<string> $messages */
    private function text(string $value, int $max, string $column, array &$messages): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            $messages[] = "{$column} is longer than {$max} characters.";

            return null;
        }

        return $value;
    }

    /** @param list<string> $messages */
    private function flag(string $value, array &$messages): ?bool
    {
        $value = mb_strtolower(trim($value));

        return match ($value) {
            '' => null,
            'yes', 'true', '1', 'y' => true,
            'no', 'false', '0', 'n' => false,
            default => (function () use (&$messages) {
                $messages[] = 'featured is yes or no.';

                return null;
            })(),
        };
    }

    /**
     * "Size: M; Colour: Red" → ["Size" => "M", "Colour" => "Red"].
     *
     * @param list<string> $messages
     * @return ?array<string, string>
     */
    private function options(string $value, array &$messages): ?array
    {
        if (trim($value) === '') {
            return null;
        }
        $out = [];
        foreach ($this->list($value) as $pair) {
            $parts = array_map('trim', explode(':', $pair, 2));
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '' || mb_strlen($parts[0]) > 60 || mb_strlen($parts[1]) > 120) {
                $messages[] = "The option \"{$pair}\" is not written as \"Name: Value\".";

                return null;
            }
            $out[$parts[0]] = $parts[1];
        }

        return count($out) > 5 ? null : $out;
    }

    /**
     * Phase B42: "ram: 16 GB; colour: Black; features: NFC, 5G; screen: 6.1;
     * waterproof: yes" → attribute id => value as ProductSpecifications takes
     * it. Attributes by key (or name), values by their text or slug; inactive
     * values are refused like in the admin.
     *
     * @param \Illuminate\Support\Collection<string, \App\Domain\Catalog\Models\Attribute> $index
     * @param list<string> $messages
     * @return array<int, mixed>
     */
    private function attributesCell(string $value, $index, array &$messages): array
    {
        $out = [];
        foreach ($this->list($value) as $pair) {
            $parts = array_map('trim', explode(':', $pair, 2));
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                $messages[] = "The attribute \"{$pair}\" is not written as \"key: value\".";

                continue;
            }
            $attribute = $index->get(mb_strtolower($parts[0])) ?? $index->first(fn ($a) => mb_strtolower($a->name) === mb_strtolower($parts[0]));
            if ($attribute === null) {
                $messages[] = "There is no attribute \"{$parts[0]}\".";

                continue;
            }
            $find = function (string $text) use ($attribute, &$messages): ?int {
                $needle = mb_strtolower(trim($text));
                $found = $attribute->values->first(fn ($v) => $v->normalized_value === $needle || $v->slug === $needle);
                if ($found === null || ! $found->is_active) {
                    $messages[] = "\"{$text}\" is not a value of {$attribute->name}.";

                    return null;
                }

                return $found->id;
            };
            $raw = $parts[1];
            $out[$attribute->id] = match ($attribute->type) {
                \App\Domain\Catalog\Models\AttributeType::MultiSelect => array_values(array_filter(array_map($find, explode(',', $raw)), fn ($id) => $id !== null)),
                \App\Domain\Catalog\Models\AttributeType::Select, \App\Domain\Catalog\Models\AttributeType::Color => $find($raw),
                \App\Domain\Catalog\Models\AttributeType::Numeric => is_numeric($raw) ? (float) $raw : (function () use ($attribute, $raw, &$messages) {
                    $messages[] = "{$attribute->name}: \"{$raw}\" is not a number.";

                    return null;
                })(),
                \App\Domain\Catalog\Models\AttributeType::Boolean => $this->flag($raw, $messages),
                \App\Domain\Catalog\Models\AttributeType::Text => mb_substr($raw, 0, 500),
            };
        }

        return array_filter($out, fn ($v) => $v !== null && $v !== []);
    }

    /** @param list<string> $messages */
    private function sortPriority(string $value, array &$messages): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^-?\d{1,4}$/', $value) !== 1 || abs((int) $value) > 1000) {
            $messages[] = 'sort_priority is a whole number from -1000 to 1000.';

            return null;
        }

        return (int) $value;
    }

    /**
     * @param \Illuminate\Support\Collection<string, int> $index lower-cased label => id
     * @param list<string> $messages
     * @return list<int>
     */
    private function badgesCell(string $value, $index, array &$messages): array
    {
        $ids = [];
        foreach ($this->list($value) as $label) {
            $id = $index->get(mb_strtolower($label));
            if ($id === null) {
                $messages[] = "There is no badge \"{$label}\". Create it under Catalog → Badges first.";
            } else {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<string> a list cell, separated by ; or | */
    private function list(string $value): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[;|]/', $value) ?: []), fn ($v) => $v !== '')));
    }

    private function canSeeCost(User $actor): bool
    {
        return Gate::forUser($actor)->allows('viewCostPrices', Product::class);
    }

    /** How many more products the package allows now, or null for no limit. */
    private function remainingProducts(): ?int
    {
        $remaining = $this->entitlements->remaining('max_products');

        return $remaining === null ? null : max(0, $remaining);
    }

    /**
     * @return array{0: list<string>, 1: list<array<string, string>>}
     */
    private function read(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            throw new CatalogImportException('The file could not be read.', 'unreadable', 422);
        }

        try {
            $first = fgets($handle);
            if ($first === false) {
                throw new CatalogImportException('The file is empty.', 'empty', 422);
            }
            $first = (string) preg_replace('/^\xEF\xBB\xBF/', '', $first);
            $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
            $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), str_getcsv($first, $delimiter));
            if (! in_array('name', $header, true) && ! in_array('sku', $header, true) && ! in_array('id', $header, true)) {
                throw new CatalogImportException('The first row must name the columns (at least "name", "sku" or "id"). Allowed: '.implode(', ', self::COLUMNS).'.', 'bad_header', 422);
            }
            $unknown = array_diff(array_filter($header), self::COLUMNS);
            if ($unknown !== []) {
                throw new CatalogImportException('Unknown columns: '.implode(', ', $unknown).'. Allowed: '.implode(', ', self::COLUMNS).'.', 'bad_header', 422);
            }

            $records = [];
            while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($cells === [null] || implode('', array_map('strval', $cells)) === '') {
                    continue;
                }
                if (count($records) >= self::MAX_ROWS) {
                    throw new CatalogImportException('The file has more than '.self::MAX_ROWS.' rows. Split it into smaller files.', 'too_many_rows', 422);
                }
                $record = [];
                foreach ($header as $position => $column) {
                    // The export puts ' before a cell a spreadsheet would run as a formula; take it off again.
                    $record[$column] = (string) preg_replace("/^'(?=[=+\-@\t\r])/", '', (string) ($cells[$position] ?? ''));
                }
                $records[] = $record;
            }

            return [$header, $records];
        } finally {
            fclose($handle);
        }
    }

    private function cacheKey(string $id, User $actor): string
    {
        return "product-import:{$this->context->storeId()}:{$actor->id}:{$id}";
    }
}
