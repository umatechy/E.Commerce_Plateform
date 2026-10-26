<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Models;

use App\Domain\Orders\Models\Order;
use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase P2: what one order earns. Pending until the order is paid and
 * delivered and the store's hold is over; then the points are written to
 * the ledger. A refund takes back the same share.
 *
 * @property int $id
 * @property int $store_id
 * @property int $order_id
 * @property int $customer_id
 * @property string $status pending | earned | cancelled
 * @property int $base_minor
 * @property int $points
 * @property int $reversed_points
 * @property int $bonus_points
 * @property array<string, mixed> $snapshot
 * @property ?\Illuminate\Support\Carbon $hold_until
 * @property ?\Illuminate\Support\Carbon $earned_at
 */
final class LoyaltyOrderAward extends Model
{
    use BelongsToTenant;

    protected $table = 'loyalty_order_awards';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array', 'hold_until' => 'datetime', 'earned_at' => 'datetime',
            'base_minor' => 'integer', 'points' => 'integer', 'reversed_points' => 'integer', 'bonus_points' => 'integer',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
