<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only ledger row (Module 12 §9). Never updated/deleted by application code. */
final class PaymentTransaction extends Model
{
    use BelongsToTenant;

    protected $table = 'payment_transactions';

    // created_at only (see migration). UPDATED_AT = null keeps Eloquent
    // filling created_at itself, so a just-created row exposes it
    // without a refresh (resources call ->toIso8601String() on it).
    public const UPDATED_AT = null;

    protected $fillable = [
        'store_id', 'payment_id', 'type', 'status', 'amount_minor', 'currency',
        'provider_transaction_reference', 'failure_code', 'failure_reason',
        'actor_id', 'idempotency_key', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'amount_minor' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
