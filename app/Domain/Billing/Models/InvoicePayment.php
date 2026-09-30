<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money received against an invoice. Append-only in practice: a mistake
 * is corrected by voiding the invoice, never by editing a payment.
 *
 * @property int $id
 * @property string $public_id
 * @property int $invoice_id
 * @property int $store_id
 * @property int $amount_minor
 * @property string $currency
 * @property InvoicePaymentMethod $method
 * @property ?string $reference
 * @property ?string $note
 * @property Carbon $received_at
 * @property ?int $recorded_by
 * @property string $idempotency_key
 */
final class InvoicePayment extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $table = 'invoice_payments';

    protected $fillable = [
        'invoice_id', 'store_id', 'amount_minor', 'currency', 'method', 'reference', 'note',
        'received_at', 'recorded_by', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'method' => InvoicePaymentMethod::class,
            'received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
