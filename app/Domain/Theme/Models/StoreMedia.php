<?php

declare(strict_types=1);

namespace App\Domain\Theme\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Phase B37 — an image a store uploaded for its brand (logo, favicon),
 * banners or social sharing. Written only by StoreMediaService.
 *
 * @property int $id
 * @property string $public_id
 * @property int $store_id
 * @property string $purpose
 * @property string $disk
 * @property string $path
 * @property string $mime
 * @property int $width
 * @property int $height
 * @property int $size_bytes
 */
final class StoreMedia extends Model
{
    use BelongsToTenant, HasPublicId;

    public const UPDATED_AT = null;

    protected $table = 'store_media';

    protected $guarded = ['id', 'public_id'];

    /** The address the storefront uses: a path on this site, so it works on every domain of the store. */
    public function sitePath(): string
    {
        return '/storage/'.$this->path;
    }

    /** A full address (for social sharing, which needs one). */
    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }
}
