<?php

declare(strict_types=1);

namespace App\Domain\Customers\Models;

use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module 10 §25: a commercial group (Retail, Wholesale, VIP ...). A
 * customer is in at most one group. A group grants no permission.
 *
 * @property int $id
 * @property string $public_id
 * @property int $store_id
 * @property string $name
 * @property ?string $description
 * @property ?int $customers_count
 */
final class CustomerGroup extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $table = 'customer_groups';

    protected $fillable = ['store_id', 'name', 'description'];

    /** @return HasMany<Customer, $this> */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'customer_group_id');
    }
}
