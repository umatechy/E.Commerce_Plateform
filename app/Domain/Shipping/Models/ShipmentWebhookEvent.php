<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Models;

use App\Domain\Payments\Models\WebhookEventStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deliberately does NOT use BelongsToTenant — same rationale as Phase
 * B7's PaymentWebhookEvent (a webhook arrives before tenant identity
 * is trusted). Reuses WebhookEventStatus from the Payments domain
 * (Received/Processed/Failed/Ignored) rather than duplicating an
 * identical enum in the Shipping domain.
 */
final class ShipmentWebhookEvent extends Model
{
    protected $table = 'shipment_webhook_events';

    /**
     * Mirrors the column defaults in the migration so a freshly created
     * model exposes them without a refresh() — resources read ->value on
     * these enum casts and threw on null (found on the first real run).
     */
    protected $attributes = [
        'status' => 'received',
    ];

    protected $fillable = [
        'store_id', 'shipment_id', 'provider', 'external_event_id',
        'status', 'payload', 'failure_reason', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => WebhookEventStatus::class,
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
