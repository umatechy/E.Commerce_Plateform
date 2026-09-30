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
 * Every upload is decoded and re-encoded with GD before it is stored.
 * That drops EXIF/XMP metadata (camera serials, GPS positions of a
 * seller's home) and guarantees the stored bytes are a plain image —
 * a file that merely claims to be one (a polyglot, an HTML payload with
 * a JPEG header) fails to decode and is refused.
 */
final class ProductImageService
{
    private const FORMATS = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];

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
        $info = @getimagesize($file->getRealPath());
        $type = $info[2] ?? null;

        if ($info === false || ! isset(self::FORMATS[$type])) {
            throw ValidationException::withMessages(['image' => 'The file must be a JPEG, PNG or WebP image.']);
        }

        [$width, $height] = $info;
        $min = (int) config('storefront.images.min_dimension');
        $max = (int) config('storefront.images.max_dimension');

        if ($width < $min || $height < $min || $width > $max || $height > $max) {
            throw ValidationException::withMessages(['image' => "Images must be between {$min} and {$max} pixels on each side."]);
        }

        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($image === false) {
            throw ValidationException::withMessages(['image' => 'The image could not be read.']);
        }

        ob_start();
        match ($type) {
            IMAGETYPE_JPEG => imagejpeg($image, null, 85),
            IMAGETYPE_PNG => (function () use ($image) {
                imagesavealpha($image, true);
                imagepng($image, null, 6);
            })(),
            IMAGETYPE_WEBP => imagewebp($image, null, 85),
        };
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return [$bytes, self::FORMATS[$type], $width, $height];
    }
}
