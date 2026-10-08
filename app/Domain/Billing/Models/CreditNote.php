<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase B47 — Module 29 §42–43, §92, §94: a correction of an issued invoice.
 * Immutable once issued (only the approval fields of a pending note change).
 *
 * Settlement: `refund` (money paid back), `account_credit` (kept as credit
 * for the next invoices), `reduce_balance` (less to pay on an open invoice).
 *
 * @property int $id
 * @property string $public_id
 * @property ?string $number
 * @property int $store_id
 * @property int $invoice_id
 * @property string $status pending_approval | issued | rejected
 * @property string $settlement
 * @property string $reason
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property list<array{description: string, amount_minor: int}> $lines
 * @property ?string $refund_method
 * @property ?string $refund_reference
 * @property ?int $requested_by
 * @property ?int $approved_by
 * @property ?\Illuminate\Support\Carbon $approved_at
 * @property ?string $rejection_reason
 * @property ?\Illuminate\Support\Carbon $issued_at
 * @property int $document_version
 * @property \Illuminate\Support\Carbon $created_at
 */
final class CreditNote extends Model
{
    use BelongsToTenant, HasPublicId;

    public const PENDING = 'pending_approval';
    public const ISSUED = 'issued';
    public const REJECTED = 'rejected';

    public const REFUND = 'refund';
    public const ACCOUNT_CREDIT = 'account_credit';
    public const REDUCE_BALANCE = 'reduce_balance';
    public const SETTLEMENTS = [self::REFUND, self::ACCOUNT_CREDIT, self::REDUCE_BALANCE];

    protected $fillable = [
        'number', 'store_id', 'invoice_id', 'status', 'settlement', 'reason', 'currency', 'subtotal_minor', 'tax_minor', 'total_minor',
        'lines', 'refund_method', 'refund_reference', 'requested_by', 'approved_by', 'approved_at', 'rejection_reason', 'issued_at',
        'document_version', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer', 'lines' => 'array',
            'approved_at' => 'datetime', 'issued_at' => 'datetime', 'document_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
