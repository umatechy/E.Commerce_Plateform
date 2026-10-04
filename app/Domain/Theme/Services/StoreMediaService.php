<?php

declare(strict_types=1);

namespace App\Domain\Theme\Services;

use App\Domain\Theme\Models\StoreMedia;
use App\Domain\Tenancy\Models\Store;
use App\Support\ImageReencoder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase B37 — Module 17 §6–7: a store's brand and banner images.
 *
 * Only JPEG and PNG are taken (no SVG, so no script can ride in an image);
 * every file is decoded and re-encoded (ImageReencoder: metadata such as
 * location is dropped, anything that is not a real image is refused). Files
 * live on the public disk under `stores/{store}/media/`, with random names.
 *
 * ownsPath() is how the theme checks that an address it stores is a file of
 * the same store (no cross-tenant media references, §7).
 */
final class StoreMediaService
{
    /** purpose => [smallest side, largest side] in pixels */
    public const PURPOSES = ['logo' => [32, 4000], 'favicon' => [16, 1024], 'banner' => [200, 6000], 'social' => [200, 6000]];

    public const MAX_KILOBYTES = 5120;

    /** A store keeps at most this many uploaded brand images. */
    public const MAX_PER_STORE = 200;

    /** `/storage/stores/{store ulid}/media/{file ulid}.{jpg|png}` */
    public const PATH_PATTERN = '#^/storage/stores/([0-9A-Za-z]{26})/media/[0-9a-z]{26}\.(?:jpg|png)$#';

    public function __construct(private readonly ImageReencoder $images) {}

    public function upload(Store $store, UploadedFile $file, string $purpose, ?int $userId): StoreMedia
    {
        if (! isset(self::PURPOSES[$purpose])) {
            throw ValidationException::withMessages(['purpose' => 'Unknown purpose.']);
        }
        if (StoreMedia::query()->count() >= self::MAX_PER_STORE) {
            throw ValidationException::withMessages(['file' => 'Your store has reached '.self::MAX_PER_STORE.' uploaded images.']);
        }
        [$min, $max] = self::PURPOSES[$purpose];
        [$bytes, $extension, $width, $height] = $this->images->reencode($file, $min, $max, 'file');
        if (! in_array($extension, ['jpg', 'png'], true)) {
            throw ValidationException::withMessages(['file' => 'Choose a JPG or PNG image.']);
        }

        $disk = (string) config('storefront.images.disk');
        $path = sprintf('stores/%s/media/%s.%s', $store->public_id, Str::lower((string) Str::ulid()), $extension);
        Storage::disk($disk)->put($path, $bytes, ['visibility' => 'public']);

        try {
            return DB::transaction(fn () => StoreMedia::query()->create([
                'store_id' => $store->id, 'purpose' => $purpose, 'disk' => $disk, 'path' => $path,
                'mime' => ImageReencoder::MIME[$extension], 'width' => $width, 'height' => $height,
                'size_bytes' => strlen($bytes), 'uploaded_by_user_id' => $userId,
            ]));
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path); // never leave an orphaned file behind

            throw $e;
        }
    }

    /** Whether an address is an uploaded image of this store. */
    public function ownsPath(Store $store, string $sitePath): bool
    {
        if (! preg_match(self::PATH_PATTERN, $sitePath, $m) || strcasecmp($m[1], $store->public_id) !== 0) {
            return false;
        }

        return StoreMedia::query()->withoutTenantScope()->where('store_id', $store->id)->where('path', substr($sitePath, strlen('/storage/')))->exists();
    }
}
