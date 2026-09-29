<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ShippingRate extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'shipping_rates';

    protected $fillable = ['store_id', 'shipping_zone_id', 'shipping_method_id', 'currency', 'base_cost_minor', 'per_unit_cost_minor', 'unit_threshold'];

    protected function casts(): array
    {
        return ['base_cost_minor' => 'integer', 'per_unit_cost_minor' => 'integer', 'unit_threshold' => 'decimal:3'];
    }

    /** @return BelongsTo<ShippingZone, $this> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }

    /** @return BelongsTo<ShippingMethod, $this> */
    public function method(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class, 'shipping_method_id');
    }
}
