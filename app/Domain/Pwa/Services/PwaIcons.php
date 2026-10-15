<?php

declare(strict_types=1);

namespace App\Domain\Pwa\Services;

use App\Domain\Theme\Models\StoreMedia;
use App\Domain\Theme\Services\StoreMediaService;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Phase B55 (Module 25 §57): the app icon. One square JPG or PNG of at
 * least 512 pixels is uploaded as the store's own media (re-encoded, no
 * metadata, B37); the sizes browsers need are made from it as PNG files
 * next to it. Only an icon that is this store's own file is ever used.
 */
final class PwaIcons
{
    public const SIZES = [192, 512];

    public function __construct(private readonly StoreMediaService $media) {}

    public function upload(Store $store, UploadedFile $file, ?int $userId): string
    {
        $info = @getimagesize($file->getRealPath());
        if ($info !== false && $info[0] !== $info[1]) {
            throw ValidationException::withMessages(['file' => 'The app icon must be square, for example 512 × 512 pixels.']);
        }
        $media = $this->media->upload($store, $file, 'app_icon', $userId);
        $disk = Storage::disk($media->disk);
        $source = @imagecreatefromstring((string) $disk->get($media->path));
        if ($source === false) {
            throw ValidationException::withMessages(['file' => 'The image could not be read.']);
        }
        foreach (self::SIZES as $size) {
            $icon = imagecreatetruecolor($size, $size);
            imagealphablending($icon, false);
            imagesavealpha($icon, true);
            imagefill($icon, 0, 0, (int) imagecolorallocatealpha($icon, 0, 0, 0, 127));
            imagecopyresampled($icon, $source, 0, 0, 0, 0, $size, $size, imagesx($source), imagesy($source));
            ob_start();
            imagepng($icon, null, 6);
            $disk->put(substr(self::variantPath('/storage/'.$media->path, $size), strlen('/storage/')), (string) ob_get_clean(), ['visibility' => 'public']);
            imagedestroy($icon);
        }
        imagedestroy($source);

        return '/storage/'.$media->path;
    }

    /** Whether $sitePath is an app icon this store uploaded. */
    public function belongsTo(Store $store, string $sitePath): bool
    {
        return $this->media->ownsPath($store, $sitePath)
            && StoreMedia::query()->withoutTenantScope()->where('store_id', $store->id)->where('path', substr($sitePath, strlen('/storage/')))->value('purpose') === 'app_icon';
    }

    /** `/storage/stores/{store}/media/{file}.png` → `…/{file}-192.png` */
    public static function variantPath(string $source, int $size): string
    {
        return (string) preg_replace('/\.(?:jpg|png)$/', "-{$size}.png", $source);
    }
}
