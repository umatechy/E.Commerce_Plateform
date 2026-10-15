<?php

declare(strict_types=1);

namespace App\Domain\Affiliates\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase B57 (Module 28 §5–6, §9–10): someone who promotes the store and
 * earns commission. Kept apart from customers and staff (§6); a link to a
 * customer record only serves the self-referral check. Payout details
 * are encrypted and shown masked.
 */
final class Affiliate extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $table = 'affiliates';

    protected $guarded = ['id'];

    protected $hidden = ['payout_details', 'portal_token_hash'];

    protected function casts(): array
    {
        return [
            'payout_details' => 'encrypted:array', 'terms_accepted_at' => 'datetime', 'approved_at' => 'datetime', 'terms_version' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<AffiliateProgram, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(AffiliateProgram::class, 'affiliate_program_id');
    }

    /** @return HasMany<AffiliateCommission, $this> */
    public function commissions(): HasMany
    {
        return $this->hasMany(AffiliateCommission::class);
    }
}
