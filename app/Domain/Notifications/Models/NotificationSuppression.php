<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class NotificationSuppression extends Model
{
    use BelongsToTenant;

    protected $table = 'notification_suppressions';

    public $timestamps = false; // created_at only, see migration

    protected $fillable = ['store_id', 'channel', 'destination', 'reason'];

    protected function casts(): array
    {
        return ['channel' => NotificationChannel::class, 'created_at' => 'datetime'];
    }
}
