<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase B24 — the only writer of product images.
 *
 * Every upload is decoded and re-encoded before it is stored (see
 * App\Support\ImageReencoder): no metadata, and only real images.
 */
final class ProductImageService
{
    public function add(Product $product, UploadedFile $file, ?string $alt, ?int $variantId): ProductImage
    {
        if ($product->images()->count() >= (int) config('storefront.images.max_per_product')) {
            throw ValidationException::withMessages(['image' => 'This product already has the maximum number of images.']);
        }

        [$bytes, $extension, $width, $height] = $this->reencode($file);

        $disk = (string) config('storefront.images.disk');
        $path = sprintf('stores/%s/products/%s/%s.%s', $product->store->public_id, $product->public_id, Str::lower((string) Str::ulid()), $extension);
        Storage::disk($disk)->put($path, $bytes, ['visibility' => 'public']);

        try {
            return DB::transaction(fn () => ProductImage::query()->create([
                'store_id' => $product->store_id,
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
                'disk' => $disk,
                'path' => $path,
                'alt' => $alt,
                'position' => (int) $product->images()->max('position') + 1,
                'width' => $width,
                'height' => $height,
                'size_bytes' => strlen($bytes),
            ]));
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path); // never leave an orphaned file behind

            throw $e;
        }
    }

    public function remove(ProductImage $image): void
    {
        $image->delete();
        Storage::disk($image->disk)->delete($image->path);
    }

    /** @param list<string> $orderedPublicIds every image of the product, in the new order */
    public function reorder(Product $product, array $orderedPublicIds): void
    {
        $images = $product->images()->get()->keyBy('public_id');

        if (count($orderedPublicIds) !== $images->count() || array_diff($orderedPublicIds, $images->keys()->all()) !== []) {
            throw ValidationException::withMessages(['images' => 'List every image of this product exactly once.']);
        }

        DB::transaction(function () use ($images, $orderedPublicIds) {
            foreach ($orderedPublicIds as $position => $publicId) {
                $images[$publicId]->update(['position' => $position + 1]);
            }
        });
    }

    /** @return array{0: string, 1: string, 2: int, 3: int} bytes, extension, width, height */
    private function reencode(UploadedFile $file): array
    {
        return app(\App\Support\ImageReencoder::class)->reencode($file, (int) config('storefront.images.min_dimension'), (int) config('storefront.images.max_dimension'));
    }
}
