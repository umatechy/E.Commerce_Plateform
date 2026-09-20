<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only — never updated/deleted by application code (Module 21 §28). */
final class NotificationDeliveryAttempt extends Model
{
    use BelongsToTenant;

    protected $table = 'notification_delivery_attempts';

    public $timestamps = false; // occurred_at only, see migration

    protected $fillable = [
        'store_id', 'notification_message_id', 'attempt_number', 'provider',
        'provider_message_id', 'result', 'failure_code', 'failure_reason', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['result' => DeliveryAttemptResult::class, 'occurred_at' => 'datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(NotificationMessage::class, 'notification_message_id');
    }
}
