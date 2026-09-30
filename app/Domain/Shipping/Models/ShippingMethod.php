<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ShippingMethod extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'shipping_methods';

    protected $fillable = ['store_id', 'name', 'type', 'is_active', 'free_shipping_threshold_minor'];

    protected function casts(): array
    {
        return ['type' => ShippingMethodType::class, 'is_active' => 'boolean'];
    }

    /** @return HasMany<ShippingRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(ShippingRate::class);
    }
}
