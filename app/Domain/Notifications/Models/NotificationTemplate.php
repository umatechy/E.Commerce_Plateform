<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class NotificationTemplate extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'notification_templates';

    protected $fillable = ['store_id', 'key', 'channel', 'locale', 'subject', 'body', 'is_published'];

    protected function casts(): array
    {
        return ['channel' => NotificationChannel::class, 'is_published' => 'boolean'];
    }
}
