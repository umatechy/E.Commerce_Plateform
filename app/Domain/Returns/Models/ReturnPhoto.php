<?php

declare(strict_types=1);

namespace App\Domain\Returns\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 09 §45 "Images" (Phase B34): a photo on a return request. The
 * file is on a private disk and has no public URL; it is read through
 * the API by the store's staff and by the person who asked.
 *
 * @property int $id
 * @property string $public_id
 * @property int $store_id
 * @property int $return_request_id
 * @property string $disk
 * @property string $path
 * @property string $mime
 * @property int $width
 * @property int $height
 * @property int $size_bytes
 * @property string $uploaded_by
 * @property \Illuminate\Support\Carbon $created_at
 */
final class ReturnPhoto extends Model
{
    use BelongsToTenant, HasPublicId;

    public const UPDATED_AT = null;

    protected $table = 'return_request_photos';

    protected $guarded = ['id', 'public_id'];

    /** @return BelongsTo<ReturnRequest, $this> */
    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }
}
