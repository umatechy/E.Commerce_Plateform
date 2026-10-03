<?php

declare(strict_types=1);

namespace App\Domain\Returns\Services;

use App\Domain\Returns\Exceptions\ReturnActionRefusedException;
use App\Domain\Returns\Models\ReturnPhoto;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Returns\Models\ReturnStatus;
use App\Support\ImageReencoder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 09 §45 "Images" (Phase B34): the only writer and reader of
 * return photos.
 *
 * - Re-encoded before storage (ImageReencoder): no metadata such as a
 *   GPS position, and only real JPEG / PNG / WebP images.
 * - Private: stored on the disk of config/returns.php (not the public
 *   one), under the store and the return; served only through the API
 *   after the caller's right to the return was checked, with
 *   `nosniff`, no caching by shared caches, and as an image only.
 * - Bounded: a fixed number per return and a size limit.
 * - The person who asked adds photos while the store has not decided
 *   yet; staff while the return is open.
 */
final class ReturnPhotoService
{
    public function __construct(private readonly ImageReencoder $images) {}

    /** @param 'customer'|'guest'|'staff' $uploadedBy */
    public function add(ReturnRequest $return, UploadedFile $file, string $uploadedBy, ?int $userId = null): ReturnPhoto
    {
        $staff = $uploadedBy === 'staff';
        if ($staff ? $return->status->isClosed() : ! in_array($return->status, [ReturnStatus::Requested, ReturnStatus::UnderReview], true)) {
            throw new ReturnActionRefusedException($staff
                ? 'This return is closed. Photos can no longer be added.'
                : 'Photos can be added until the store has answered the request.', 'photos_closed');
        }

        $max = (int) config('returns.photos.max_per_return');
        if ($return->photos()->count() >= $max) {
            throw ValidationException::withMessages(['photo' => "A return can have at most {$max} photos."]);
        }

        [$bytes, $extension, $width, $height] = $this->images->reencode(
            $file, (int) config('returns.photos.min_dimension'), (int) config('returns.photos.max_dimension'), 'photo',
        );

        $disk = (string) config('returns.photos.disk');
        $path = sprintf('stores/%d/returns/%s/%s.%s', $return->store_id, $return->public_id, Str::lower((string) Str::ulid()), $extension);
        Storage::disk($disk)->put($path, $bytes, ['visibility' => 'private']);

        try {
            return DB::transaction(fn () => ReturnPhoto::query()->create([
                'store_id' => $return->store_id,
                'return_request_id' => $return->id,
                'disk' => $disk,
                'path' => $path,
                'mime' => ImageReencoder::MIME[$extension],
                'width' => $width,
                'height' => $height,
                'size_bytes' => strlen($bytes),
                'uploaded_by' => $uploadedBy,
                'uploaded_by_user_id' => $userId,
            ]));
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path); // never leave an orphaned file behind

            throw $e;
        }
    }

    public function remove(ReturnPhoto $photo): void
    {
        $photo->delete();
        Storage::disk($photo->disk)->delete($photo->path);
    }

    /** Removes every photo of these returns, files included (Module 32 erasure). */
    public function removeForReturns(iterable $returnIds): int
    {
        $removed = 0;
        foreach (ReturnPhoto::query()->whereIn('return_request_id', collect($returnIds)->all())->get() as $photo) {
            $this->remove($photo);
            $removed++;
        }

        return $removed;
    }

    /** The image itself, for a caller whose right to the return was already checked. */
    public function response(ReturnPhoto $photo): Response
    {
        $disk = Storage::disk($photo->disk);
        if (! $disk->exists($photo->path)) {
            abort(404);
        }

        return response($disk->get($photo->path), 200, [
            'Content-Type' => $photo->mime,
            'Content-Length' => (string) $photo->size_bytes,
            'Content-Disposition' => 'inline; filename="return-photo.'.pathinfo($photo->path, PATHINFO_EXTENSION).'"',
            'X-Content-Type-Options' => 'nosniff',
            // A person's photo: the browser of the viewer may keep it, nothing in between.
            'Cache-Control' => 'private, max-age=600',
        ]);
    }
}
