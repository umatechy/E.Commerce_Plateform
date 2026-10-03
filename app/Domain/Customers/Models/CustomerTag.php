<?php

declare(strict_types=1);

namespace App\Domain\Customers\Models;

use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Module 10 §24: a tenant-scoped label. "VIP" and " vip " are one tag:
 * uniqueness is on the normalized name.
 *
 * @property int $id
 * @property string $public_id
 * @property int $store_id
 * @property string $name
 * @property string $normalized_name
 * @property ?int $customers_count
 */
final class CustomerTag extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $table = 'customer_tags';

    protected $fillable = ['store_id', 'name', 'normalized_name'];

    public static function normalize(string $name): string
    {
        return Str::lower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /** @return BelongsToMany<Customer, $this> */
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_tag_assignments', 'customer_tag_id', 'customer_id');
    }
}
