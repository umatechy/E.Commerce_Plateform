<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deliberately does NOT use BelongsToTenant. A webhook arrives BEFORE
 * tenant identity is known or trusted (Step 10: a webhook payload's
 * claimed tenant/order/payment IDs must be independently verified, not
 * assumed) — this row's `store_id` is populated only AFTER the
 * referenced Payment is resolved and its signature verified, for
 * later audit/query convenience, not as an enforced isolation
 * boundary on this specific model. Compare to Permission/Package
 * (platform-level catalogs) — this is the same category of documented
 * exception, for a different reason (chronological, not ownership).
 */
final class PaymentWebhookEvent extends Model
{
    protected $table = 'payment_webhook_events';

    protected $fillable = [
        'store_id', 'payment_id', 'provider', 'external_event_id',
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

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
