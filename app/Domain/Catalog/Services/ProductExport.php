<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductStatus;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Customers\Services\CustomerExport;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Services\Currencies;
use App\Support\RequestId;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase B40 — Module 06 §51: the catalog as a CSV file in the import's own
 * columns, so an exported file can be edited and imported back (matched by
 * id). Products, then each product's variants as rows with parent_id.
 * Tenant-scoped (the product query), audited, cost prices only for those who
 * may see them; image_urls are the pictures' public addresses (export only).
 * Cells a spreadsheet would run as a formula are neutralized.
 */
final class ProductExport
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array{search?: ?string, status?: ?string} $filters */
    public function stream(array $filters, User $actor): StreamedResponse
    {
        $withCost = Gate::forUser($actor)->allows('viewCostPrices', Product::class);
        $header = array_values(array_filter(ProductImport::COLUMNS, fn ($c) => $withCost || $c !== 'cost_price'));
        $query = Product::query()
            ->with(['brand', 'primaryCategory', 'categories', 'tags', 'collections', 'images', 'variants' => fn ($q) => $q->orderBy('id'), 'attributeValues.attribute', 'attributeValues.choice'])
            ->when($filters['search'] ?? null, function ($q, string $search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like));
            })
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->orderBy('id');
        $count = (clone $query)->count();
        $paths = $this->categoryPaths();

        $this->audit->record('products.exported', ['rows' => $count, 'filters' => array_filter($filters), 'cost_prices' => $withCost], actor: $actor);

        return new StreamedResponse(function () use ($query, $header, $paths) {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            $query->chunk(200, function ($products) use ($out, $header, $paths) {
                foreach ($products as $product) {
                    /** @var Product $product */
                    $currency = (string) $product->currency;
                    $money = fn (?int $minor) => $minor === null ? '' : Currencies::amount($minor, $currency);
                    $this->line($out, $header, [
                        'id' => $product->public_id, 'sku' => (string) $product->sku, 'name' => $product->name, 'type' => $product->type->value,
                        'status' => $product->status->value, 'visibility' => $product->visibility->value,
                        'short_description' => (string) $product->short_description, 'description' => (string) $product->description,
                        'price' => $money($product->price_minor), 'sale_price' => $money($product->sale_price_minor), 'cost_price' => $money($product->cost_price_minor),
                        'currency' => $currency, 'brand' => (string) $product->brand?->name,
                        'category' => $product->primary_category_id !== null ? ($paths[$product->primary_category_id] ?? '') : '',
                        'categories' => $product->categories->where('id', '!=', $product->primary_category_id)->map(fn (Category $c) => $paths[$c->id] ?? $c->name)->implode('; '),
                        'tags' => $product->tags->pluck('name')->implode('; '),
                        'collections' => $product->collections->pluck('name')->implode('; '),
                        'featured' => $product->is_featured ? 'yes' : 'no',
                        'image_urls' => $product->images->map(fn ($image) => $image->url())->implode('; '),
                        'attributes' => self::attributes($product),
                    ]);
                    foreach ($product->variants as $variant) {
                        $this->line($out, $header, [
                            'parent_id' => $product->public_id, 'sku' => (string) $variant->sku, 'barcode' => (string) $variant->barcode,
                            'status' => (string) $variant->status,
                            'options' => collect($variant->option_values ?? [])->map(fn ($value, $name) => "{$name}: {$value}")->implode('; '),
                            'price' => $money($variant->price_minor), 'sale_price' => $money($variant->sale_price_minor), 'cost_price' => $money($variant->cost_price_minor),
                            'currency' => $currency,
                        ]);
                    }
                }
            });
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="products-'.now()->format('Y-m-d').'.csv"',
            'Cache-Control' => 'no-store, private',
            'X-Request-Id' => (string) RequestId::current(),
        ]);
    }

    /**
     * @param resource $out
     * @param list<string> $header
     * @param array<string, string> $values
     */
    private function line($out, array $header, array $values): void
    {
        fputcsv($out, array_map(fn (string $column) => CustomerExport::safe($values[$column] ?? ''), $header));
    }

    /** @return array<int, string> category id => "Parent > Child" */
    private function categoryPaths(): array
    {
        $all = Category::query()->get(['id', 'parent_id', 'name'])->keyBy('id');
        $paths = [];
        foreach ($all as $category) {
            $names = [];
            for ($c = $category, $depth = 0; $c !== null && $depth < 10; $c = $c->parent_id !== null ? $all->get($c->parent_id) : null, $depth++) {
                array_unshift($names, $c->name);
            }
            $paths[$category->id] = implode(' > ', $names);
        }

        return $paths;
    }

    /** Phase B42: the product's specifications in the import's "key: value; …" form. */
    private static function attributes(Product $product): string
    {
        return $product->attributeValues->filter(fn ($row) => $row->attribute !== null)->groupBy('attribute_id')
            ->map(function ($group) {
                $attribute = $group->first()->attribute;
                $first = $group->first();
                $value = match ($attribute->type) {
                    \App\Domain\Catalog\Models\AttributeType::Numeric => rtrim(rtrim(number_format((float) $first->number_value, 4, '.', ''), '0'), '.'),
                    \App\Domain\Catalog\Models\AttributeType::Boolean => $first->bool_value ? 'yes' : 'no',
                    \App\Domain\Catalog\Models\AttributeType::Text => str_replace([';', '|'], ',', (string) $first->text_value),
                    default => $group->map(fn ($row) => $row->choice?->value)->filter()->implode(', '),
                };

                return "{$attribute->key}: {$value}";
            })->values()->implode('; ');
    }

    /** @return list<string> statuses accepted as an export filter */
    public static function statuses(): array
    {
        return array_map(fn (ProductStatus $s) => $s->value, ProductStatus::cases());
    }
}
