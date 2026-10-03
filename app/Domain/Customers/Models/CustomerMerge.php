<?php

declare(strict_types=1);

namespace App\Domain\Customers\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 10 §56 "CustomerMergeRecord": one customer record joined into
 * another of the same store, by whom, why, and how much moved (counts
 * only — no personal data).
 *
 * @property int $id
 * @property string $public_id
 * @property int $store_id
 * @property int $source_customer_id
 * @property int $target_customer_id
 * @property ?int $actor_user_id
 * @property string $reason
 * @property array<string, int> $moved
 * @property \Illuminate\Support\Carbon $created_at
 */
final class CustomerMerge extends Model
{
    use BelongsToTenant, HasPublicId;

    public const UPDATED_AT = null;

    protected $table = 'customer_merges';

    protected $fillable = ['store_id', 'source_customer_id', 'target_customer_id', 'actor_user_id', 'reason', 'moved'];

    protected function casts(): array
    {
        return ['moved' => 'array'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'source_customer_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'target_customer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
