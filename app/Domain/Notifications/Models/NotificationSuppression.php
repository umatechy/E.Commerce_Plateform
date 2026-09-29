<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class NotificationSuppression extends Model
{
    use BelongsToTenant;

    protected $table = 'notification_suppressions';

    // created_at only (see migration). UPDATED_AT = null keeps Eloquent
    // filling created_at itself, so a just-created row exposes it
    // without a refresh (resources call ->toIso8601String() on it).
    public const UPDATED_AT = null;

    protected $fillable = ['store_id', 'channel', 'destination', 'reason'];

    protected function casts(): array
    {
        return ['channel' => NotificationChannel::class, 'created_at' => 'datetime'];
    }
}
