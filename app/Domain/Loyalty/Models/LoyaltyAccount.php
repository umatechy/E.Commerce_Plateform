<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Models;

use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase P2: a customer's points. Changed only by LoyaltyLedger, under a lock.
 *
 * @property int $id
 * @property int $store_id
 * @property int $customer_id
 * @property int $balance
 * @property int $lifetime_points
 * @property ?\Illuminate\Support\Carbon $last_activity_at
 */
final class LoyaltyAccount extends Model
{
    use BelongsToTenant;

    protected $table = 'loyalty_accounts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['balance' => 'integer', 'lifetime_points' => 'integer', 'last_activity_at' => 'datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
