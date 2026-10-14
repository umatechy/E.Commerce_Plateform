<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Payments\Providers\FailureCategory;
use App\Domain\Payments\Providers\WalletCharge;
use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase B48 — Module 12 §8, §41–43; SRS PAY-004: one try to collect money
 * online. Attempts are never overwritten: a retry is a new attempt, and a
 * failed one stays as history.
 *
 * `purpose`: order (a shopper paying a store), invoice (a store paying Umar
 * Techy — Module 29) or test (a store checking its sandbox account). The
 * store_id is always the store involved, so the store sees its own attempts
 * and no other store's.
 *
 * @property int $id
 * @property string $public_id
 * @property int $store_id
 * @property string $purpose
 * @property ?int $payment_id
 * @property ?int $invoice_id
 * @property ?int $provider_account_id
 * @property ?int $platform_account_id
 * @property string $provider
 * @property string $environment
 * @property string $reference
 * @property int $amount_minor
 * @property string $currency
 * @property ?string $payer_hint
 * @property string $status
 * @property ?string $failure_category
 * @property ?string $provider_code
 * @property ?string $provider_message
 * @property ?string $provider_transaction_reference
 * @property ?int $confirmed_amount_minor
 * @property ?string $confirmed_currency
 * @property int $check_count
 * @property ?\Illuminate\Support\Carbon $last_checked_at
 * @property \Illuminate\Support\Carbon $expires_at
 * @property ?\Illuminate\Support\Carbon $completed_at
 * @property ?int $created_by
 * @property \Illuminate\Support\Carbon $created_at
 */
final class PaymentAttempt extends Model
{
    use BelongsToTenant, HasPublicId;

    public const PENDING = 'pending';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const UNKNOWN = 'unknown';

    public const ORDER = 'order';

    public const INVOICE = 'invoice';

    public const TEST = 'test';

    protected $table = 'payment_attempts';

    protected $attributes = ['status' => self::PENDING, 'check_count' => 0];

    protected $fillable = [
        'store_id', 'purpose', 'payment_id', 'invoice_id', 'provider_account_id', 'platform_account_id',
        'provider', 'environment', 'reference', 'amount_minor', 'currency', 'payer_hint', 'status',
        'failure_category', 'provider_code', 'provider_message', 'provider_transaction_reference',
        'confirmed_amount_minor', 'confirmed_currency', 'check_count', 'last_checked_at', 'expires_at',
        'completed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'confirmed_amount_minor' => 'integer',
            'check_count' => 'integer',
            'last_checked_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<PaymentProviderAccount, $this> */
    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentProviderAccount::class, 'provider_account_id');
    }

    /** @return BelongsTo<PlatformPaymentAccount, $this> */
    public function platformAccount(): BelongsTo
    {
        return $this->belongsTo(PlatformPaymentAccount::class, 'platform_account_id');
    }

    /** Still waiting for an answer: a new attempt must not start (Module 30 §96). */
    public function isInFlight(): bool
    {
        return in_array($this->status, [self::PENDING, self::UNKNOWN], true);
    }

    public function failureCategory(): ?FailureCategory
    {
        return $this->failure_category !== null ? FailureCategory::tryFrom($this->failure_category) : null;
    }

    /** The charge as the provider knows it, for inquiries and refunds (no wallet number: it was never stored). */
    public function asCharge(string $description = ''): WalletCharge
    {
        return new WalletCharge(
            reference: $this->reference, amountMinor: $this->amount_minor, currency: $this->currency,
            description: $description, createdAt: CarbonImmutable::instance($this->created_at),
            expiresAt: CarbonImmutable::instance($this->expires_at), payerHint: $this->payer_hint,
            providerTransactionReference: $this->provider_transaction_reference,
        );
    }
}
