<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase B51: one change in a store's life — who, when, why. Never purged
 * (StoreDataMap::RETAIN), so the history survives a permanent deletion.
 * Platform-level history: read by the Super Admin and by the store's owner
 * on the Close store page, always by explicit store id (not tenant-scoped).
 */
final class StoreLifecycleEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'store_lifecycle_events';

    protected $fillable = ['store_id', 'action', 'from_status', 'to_status', 'reason', 'note', 'actor_type', 'actor_user_id', 'request_id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
