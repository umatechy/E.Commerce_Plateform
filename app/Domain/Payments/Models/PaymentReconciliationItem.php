<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase B48 — Module 12 §53–55: something reconciliation found that a
 * person must look at. One per attempt and kind, so a check that runs again
 * does not pile up the same finding.
 *
 * Kinds: amount_mismatch, currency_mismatch (the provider confirmed a
 * different amount or currency — not recorded as paid), paid_after_expiry
 * (the provider confirmed after the order was given up — usually a refund
 * is due), status_changed (the provider now reports a recorded payment as
 * failed or reversed), unresolved_unknown (no answer after a day).
 *
 * @property int $id
 * @property string $public_id
 * @property int $store_id
 * @property ?int $payment_id
 * @property int $payment_attempt_id
 * @property string $kind
 * @property ?array<string, mixed> $details
 * @property string $status
 * @property ?string $resolution_note
 * @property ?int $resolved_by
 * @property ?\Illuminate\Support\Carbon $resolved_at
 * @property \Illuminate\Support\Carbon $created_at
 */
final class PaymentReconciliationItem extends Model
{
    use BelongsToTenant, HasPublicId;

    public const KINDS = ['amount_mismatch', 'currency_mismatch', 'paid_after_expiry', 'status_changed', 'unresolved_unknown'];

    protected $table = 'payment_reconciliation_items';

    protected $attributes = ['status' => 'open'];

    protected $fillable = ['store_id', 'payment_id', 'payment_attempt_id', 'kind', 'details', 'status', 'resolution_note', 'resolved_by', 'resolved_at'];

    protected function casts(): array
    {
        return ['details' => 'array', 'resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<PaymentAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id');
    }
}
