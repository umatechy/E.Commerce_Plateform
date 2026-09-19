<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module 12 §5-6. "Dumb" aggregate like Order/Inventory — PaymentService
 * is the only writer of `status`; no controller/webhook handler sets it
 * directly (Non-Negotiable Rule #6).
 */
final class Payment extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'payments';

    protected $fillable = [
        'store_id', 'order_id', 'customer_id', 'method', 'status',
        'amount_minor', 'currency', 'provider_payment_reference',
        'idempotency_key', 'metadata', 'completed_at', 'failed_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'metadata' => 'array',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /**
     * Module 12 §48 "Refundable Balance" — computed from the
     * authoritative transaction ledger, never a stored/cached value.
     */
    public function paidAmountMinor(): int
    {
        return (int) $this->transactions()
            ->whereIn('type', [TransactionType::Capture, TransactionType::Sale])
            ->where('status', TransactionStatus::Succeeded)
            ->sum('amount_minor');
    }

    public function refundedAmountMinor(): int
    {
        return (int) $this->transactions()
            ->whereIn('type', [TransactionType::Refund, TransactionType::PartialRefund])
            ->where('status', TransactionStatus::Succeeded)
            ->sum('amount_minor');
    }

    public function refundableAmountMinor(): int
    {
        return max(0, $this->paidAmountMinor() - $this->refundedAmountMinor());
    }
}
