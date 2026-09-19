<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Warehouse extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'warehouses';

    protected $fillable = [
        'store_id', 'name', 'code', 'address', 'contact',
        'status', 'is_default', 'fulfillment_priority',
    ];

    protected function casts(): array
    {
        return [
            'status' => WarehouseStatus::class,
            'is_default' => 'boolean',
        ];
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }
}
