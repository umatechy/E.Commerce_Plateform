<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Append-only. */
final class WebhookDeliveryAttempt extends Model
{
    use BelongsToTenant;

    protected $table = 'webhook_delivery_attempts';

    public $timestamps = false;

    protected $fillable = ['store_id', 'webhook_subscription_id', 'event_type', 'idempotency_key', 'attempt_number', 'result', 'response_status'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
