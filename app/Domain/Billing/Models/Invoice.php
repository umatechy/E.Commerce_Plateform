<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Module 29 — what the platform charges a store for one subscription
 * period. Tenant-scoped (a store sees only its own); amounts are fixed
 * at issue time. Only InvoiceService changes an invoice.
 *
 * @property int $id
 * @property string $public_id
 * @property string $number
 * @property int $store_id
 * @property int $subscription_id
 * @property int $package_id
 * @property InvoiceStatus $status
 * @property BillingReason $billing_reason
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $tax_rate_bps
 * @property int $tax_minor
 * @property int $total_minor
 * @property int $amount_paid_minor
 * @property array<string, mixed> $bill_to
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property Carbon $issued_at
 * @property Carbon $due_at
 * @property ?Carbon $paid_at
 * @property ?Carbon $voided_at
 * @property ?string $void_reason
 */
final class Invoice extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $table = 'invoices';

    protected $fillable = [
        'number', 'store_id', 'subscription_id', 'package_id', 'status', 'billing_reason',
        'currency', 'subtotal_minor', 'tax_rate_bps', 'tax_minor', 'total_minor', 'amount_paid_minor',
        'bill_to', 'period_start', 'period_end', 'issued_at', 'due_at', 'paid_at', 'voided_at', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'billing_reason' => BillingReason::class,
            'subtotal_minor' => 'integer',
            'tax_rate_bps' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'amount_paid_minor' => 'integer',
            'bill_to' => 'array',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function amountDue(): int
    {
        return $this->status === InvoiceStatus::Open ? $this->total_minor - $this->amount_paid_minor : 0;
    }

    public function isOverdue(?\DateTimeInterface $at = null): bool
    {
        return $this->status === InvoiceStatus::Open && $this->due_at->lessThanOrEqualTo($at ?? now());
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<Package, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /** @return HasMany<InvoicePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }
}
