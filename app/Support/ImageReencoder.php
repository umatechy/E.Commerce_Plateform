<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Turns an uploaded picture into plain image bytes (first written for
 * product images in B24, shared since B34).
 *
 * Every upload is decoded and re-encoded with GD before it is stored.
 * That drops EXIF/XMP metadata (camera serials, the GPS position of a
 * home) and guarantees the stored bytes are a plain image — a file that
 * merely claims to be one (a polyglot, an HTML payload with a JPEG
 * header) fails to decode and is refused.
 */
final class ImageReencoder
{
    private const FORMATS = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];

    public const MIME = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    /**
     * @return array{0: string, 1: string, 2: int, 3: int} bytes, extension, width, height
     *
     * @throws ValidationException on the field `$field`
     */
    public function reencode(UploadedFile $file, int $minDimension, int $maxDimension, string $field = 'image'): array
    {
        $info = @getimagesize($file->getRealPath());
        $type = $info[2] ?? null;

        if ($info === false || ! isset(self::FORMATS[$type])) {
            throw ValidationException::withMessages([$field => 'The file must be a JPEG, PNG or WebP image.']);
        }

        [$width, $height] = $info;

        if ($width < $minDimension || $height < $minDimension || $width > $maxDimension || $height > $maxDimension) {
            throw ValidationException::withMessages([$field => "Images must be between {$minDimension} and {$maxDimension} pixels on each side."]);
        }

        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($image === false) {
            throw ValidationException::withMessages([$field => 'The image could not be read.']);
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
