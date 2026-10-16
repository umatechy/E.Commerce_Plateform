<?php

declare(strict_types=1);

namespace App\Domain\Resellers\Models;

use App\Domain\Billing\Models\Invoice;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase B58 (Module 28 §21–25): what a reseller earns from one paid
 * Umar Techy invoice of a store they brought. Calculated once, from the
 * terms in the store's attribution; refunds by credit note take back their
 * share (reversed_minor, or an adjustment once paid).
 *
 * @property int $id
 * @property string $public_id
 * @property int $reseller_id
 * @property int $reseller_store_id
 * @property int $store_id
 * @property int $invoice_id
 * @property string $kind
 * @property string $status
 * @property int $base_minor
 * @property int $rate_bp
 * @property int $amount_minor
 * @property int $reversed_minor
 * @property string $currency
 * @property ?\Illuminate\Support\Carbon $hold_until
 * @property ?string $decision_note
 * @property ?int $reseller_payout_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read Reseller $reseller
 * @property-read ResellerStore $attribution
 * @property-read Invoice $invoice
 */
final class ResellerCommission extends Model
{
    use HasPublicId;

    /** Statuses that still count towards what the reseller earned. */
    public const LIVE = ['pending', 'approved', 'paid'];

    protected $table = 'reseller_commissions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['base_minor' => 'integer', 'rate_bp' => 'integer', 'amount_minor' => 'integer', 'reversed_minor' => 'integer', 'hold_until' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** What is still owed for it after refunds taken back before payment. */
    public function net(): int
    {
        return max(0, $this->amount_minor - $this->reversed_minor);
    }

    /** @return BelongsTo<Reseller, $this> */
    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    /** @return BelongsTo<ResellerStore, $this> */
    public function attribution(): BelongsTo
    {
        return $this->belongsTo(ResellerStore::class, 'reseller_store_id');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withoutGlobalScopes();
    }
}
