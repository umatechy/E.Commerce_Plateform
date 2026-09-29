<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** `signing_secret` is $hidden — shown only once, at creation, via the controller's own explicit response (never re-read after). */
final class WebhookSubscription extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'webhook_subscriptions';

    /**
     * Mirrors the column defaults in the migration so a freshly created
     * model exposes them without a refresh() — resources read ->value on
     * these enum casts and threw on null (found on the first real run).
     */
    protected $attributes = [
        'status' => 'active',
    ];

    protected $fillable = ['developer_application_id', 'store_id', 'url', 'signing_secret', 'subscribed_events', 'status'];

    protected $hidden = ['signing_secret'];

    protected function casts(): array
    {
        return ['subscribed_events' => 'array', 'status' => WebhookSubscriptionStatus::class];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(DeveloperApplication::class, 'developer_application_id');
    }

    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(WebhookDeliveryAttempt::class);
    }

    public function isSubscribedTo(string $eventType): bool
    {
        return $this->status === WebhookSubscriptionStatus::Active && in_array($eventType, $this->subscribed_events, true);
    }
}
