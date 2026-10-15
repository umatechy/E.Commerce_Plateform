<?php

declare(strict_types=1);

namespace App\Domain\Affiliates\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase B57 (Module 28 §39, §69): a change to an affiliate's balance after
 * the fact — a refund after the commission was paid (automatic, negative)
 * or a correction by the store, with its reason. Settled by the next payout.
 */
final class AffiliateAdjustment extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'affiliate_adjustments';

    protected $guarded = ['id'];
}
