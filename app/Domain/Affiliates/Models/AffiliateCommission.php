<?php

declare(strict_types=1);

namespace App\Domain\Affiliates\Models;

use App\Domain\Orders\Models\Order;
use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase B57 (Module 28 §16, §21–24, §66): the commission one order earns
 * an affiliate. amount_minor is what was calculated and never changes;
 * refunds before payout raise reversed_minor, and refunds after payout
 * become adjustments.
 *
 * @property int $amount_minor
 * @property int $reversed_minor
 */
final class AffiliateCommission extends Model
{
    use BelongsToTenant, HasPublicId;

    /** Statuses that still count towards what the affiliate is owed or has been paid. */
    public const LIVE = ['pending', 'review', 'approved', 'paid'];

    protected $table = 'affiliate_commissions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'flags' => 'array', 'hold_until' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function net(): int
    {
        return max(0, $this->amount_minor - $this->reversed_minor);
    }

    /** @return BelongsTo<Affiliate, $this> */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
