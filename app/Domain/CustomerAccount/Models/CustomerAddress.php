<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccount\Models;

use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $store_id
 * @property int $customer_id
 * @property ?string $label
 * @property string $name
 * @property ?string $phone
 * @property string $line1
 * @property ?string $line2
 * @property string $city
 * @property ?string $province
 * @property ?string $postal_code
 * @property string $country
 * @property bool $is_default
 */
final class CustomerAddress extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $table = 'customer_addresses';

    protected $fillable = [
        'store_id', 'customer_id', 'label', 'name', 'phone', 'line1', 'line2',
        'city', 'province', 'postal_code', 'country', 'is_default',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The shape orders store as an address snapshot. @return array<string, ?string> */
    public function snapshot(): array
    {
        return [
            'name' => $this->name,
            'phone' => $this->phone,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'province' => $this->province,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
        ];
    }
}
