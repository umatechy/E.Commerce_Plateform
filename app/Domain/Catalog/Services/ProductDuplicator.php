<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Phase B39 — Module 06 §60 "Product duplication": a new product with a new
 * id and a safe new slug, the content copied, and everything that must be
 * unique reset:
 *
 * - name "… (copy)", status draft, hidden (so it never counts against the
 *   product limit or shows before it is ready); SKU and barcodes empty;
 * - categories, tags, specifications, hand-picked collections and variants copied (variants
 *   without SKU or barcode);
 * - images copied as new files ("copy media references safely" — removing a
 *   picture from one product never removes it from the other);
 * - stock, reviews, orders and relations are not copied.
 */
final class ProductDuplicator
{
    public function duplicate(Product $original, ?int $actorUserId): Product
    {
        $original->loadMissing(['variants', 'images', 'categories', 'tags', 'collections']);
        $copiedFiles = [];

        try {
            $copy = DB::transaction(function () use ($original, &$copiedFiles) {
                $name = Str::limit($original->name.' (copy)', 255, '');
                $copy = Product::query()->create([
                    ...$original->only(['type', 'short_description', 'description', 'brand_id', 'primary_category_id', 'price_minor', 'sale_price_minor', 'cost_price_minor', 'currency']),
                    'name' => $name,
                    'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
                    'sku' => null,
                    'status' => 'draft',
                    'visibility' => 'hidden',
                    'is_featured' => false,
                ]);
                $copy->categories()->sync($original->categories->pluck('id')->all());
                $copy->tags()->sync($original->tags->pluck('id')->all());
                // Phase B41: specifications are product content and are copied too.
                foreach (\App\Domain\Catalog\Models\ProductAttributeValue::query()->where('product_id', $original->id)->get() as $spec) {
                    \App\Domain\Catalog\Models\ProductAttributeValue::query()->create([
                        ...$spec->only(['attribute_id', 'attribute_value_id', 'number_value', 'bool_value', 'text_value']),
                        'product_id' => $copy->id,
                    ]);
                }
                foreach ($original->collections as $collection) {
                    $copy->collections()->attach($collection->id, ['position' => (int) DB::table('collection_product')->where('collection_id', $collection->id)->max('position') + 1]);
                }

                $variantMap = [];
                foreach (ProductVariant::query()->where('product_id', $original->id)->orderBy('id')->get() as $variant) {
                    $variantMap[$variant->id] = ProductVariant::query()->create([
                        ...$variant->only(['price_minor', 'sale_price_minor', 'cost_price_minor', 'weight', 'status', 'option_values']),
                        'product_id' => $copy->id, 'sku' => null, 'barcode' => null,
                    ])->id;
                }

                foreach ($original->images as $image) {
                    $disk = Storage::disk($image->disk);
                    if (! $disk->exists($image->path)) {
                        continue;
                    }
                    $path = sprintf('stores/%s/products/%s/%s.%s', $copy->store->public_id, $copy->public_id, Str::lower((string) Str::ulid()), pathinfo($image->path, PATHINFO_EXTENSION));
                    $disk->copy($image->path, $path);
                    $copiedFiles[] = [$image->disk, $path];
                    ProductImage::query()->create([
                        ...$image->only(['disk', 'alt', 'position', 'width', 'height', 'size_bytes']),
                        'product_id' => $copy->id,
                        'product_variant_id' => $image->product_variant_id !== null ? ($variantMap[$image->product_variant_id] ?? null) : null,
                        'path' => $path,
                    ]);
                }

                return $copy;
            });
        } catch (\Throwable $e) {
            foreach ($copiedFiles as [$disk, $path]) {
                Storage::disk($disk)->delete($path); // never leave copies of a failed duplicate behind
            }

            throw $e;
        }

        app(AuditLogger::class)->record('product.duplicated', ['from' => $original->public_id], $copy, null, $actorUserId ? User::query()->find($actorUserId) : null);

        return $copy;
    }
}
