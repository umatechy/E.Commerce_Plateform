<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A product image (Phase B24). Only ProductImageService writes these,
 * after re-encoding the upload.
 *
 * @property int $id
 * @property string $public_id
 * @property int $store_id
 * @property int $product_id
 * @property ?int $product_variant_id
 * @property string $disk
 * @property string $path
 * @property ?string $alt
 * @property int $position
 * @property int $width
 * @property int $height
 * @property int $size_bytes
 */
final class ProductImage extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $table = 'product_images';

    protected $fillable = ['store_id', 'product_id', 'product_variant_id', 'disk', 'path', 'alt', 'position', 'width', 'height', 'size_bytes'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'width' => 'integer', 'height' => 'integer', 'size_bytes' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
