<?php

declare(strict_types=1);

namespace App\Domain\Affiliates\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase B57 (Module 28 §33–38): money paid to an affiliate, gathering the
 * commissions and adjustments it covers. The store pays outside the
 * platform and records the bank or wallet reference; a payout is never
 * marked paid by anything the affiliate says.
 */
final class AffiliatePayout extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $table = 'affiliate_payouts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Affiliate, $this> */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }
}
