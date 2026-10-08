<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase B47 — Module 29 §73–74, §79: a store tells Umar Techy it paid an
 * invoice (bank transfer, wallet, cash). Nothing counts as paid until
 * platform staff confirm it; then it becomes a recorded payment.
 *
 * @property int $id
 * @property string $public_id
 * @property int $store_id
 * @property int $invoice_id
 * @property int $amount_minor
 * @property string $currency
 * @property string $method
 * @property string $reference
 * @property \Illuminate\Support\Carbon $paid_on
 * @property ?string $note
 * @property string $status pending | approved | rejected
 * @property ?int $submitted_by
 * @property ?int $reviewed_by
 * @property ?\Illuminate\Support\Carbon $reviewed_at
 * @property ?string $rejection_reason
 * @property ?int $invoice_payment_id
 * @property \Illuminate\Support\Carbon $created_at
 */
final class PaymentNotice extends Model
{
    use BelongsToTenant, HasPublicId;

    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $fillable = ['store_id', 'invoice_id', 'amount_minor', 'currency', 'method', 'reference', 'paid_on', 'note', 'status', 'submitted_by', 'reviewed_by', 'reviewed_at', 'rejection_reason', 'invoice_payment_id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'paid_on' => 'date', 'reviewed_at' => 'datetime'];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
