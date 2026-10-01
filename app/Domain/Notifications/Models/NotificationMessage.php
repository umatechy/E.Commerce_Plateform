<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module 21 §43 (collapsed Message+MessageRecipient+MessageContent —
 * see inspection findings). "Dumb" like every other core-state model
 * — NotificationService/DeliverNotificationJob are the only writers
 * of `status`.
 */
final class NotificationMessage extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId;

    protected $table = 'notification_messages';

    /**
     * Mirrors the column defaults in the migration so a freshly created
     * model exposes them without a refresh() — resources read ->value on
     * these enum casts and threw on null (found on the first real run).
     */
    protected $attributes = [
        'status' => 'created',
    ];

    protected $fillable = [
        'store_id', 'message_type', 'channel', 'recipient_type', 'recipient_id',
        'destination', 'notification_template_id', 'subject', 'body', 'sealed_body', 'status',
        'source_event_type', 'idempotency_key', 'read_at', 'sent_at',
    ];

    /**
     * The full text of a message that carries a secret, readable only by
     * the delivery job (see the 2028_02_01_000001 migration). Never
     * serialised: resources and logs only ever see `body`.
     */
    protected $hidden = ['sealed_body'];

    protected function casts(): array
    {
        return [
            'sealed_body' => 'encrypted',
            'message_type' => NotificationMessageType::class,
            'channel' => NotificationChannel::class,
            'recipient_type' => RecipientType::class,
            'status' => NotificationStatus::class,
            'read_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** @return HasMany<NotificationDeliveryAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(NotificationDeliveryAttempt::class);
    }

    /** @return BelongsTo<NotificationTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'notification_template_id');
    }

    /** The text a channel sends: the sealed text while it exists, else the stored body. */
    public function deliverableBody(): string
    {
        return $this->sealed_body ?? $this->body;
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
