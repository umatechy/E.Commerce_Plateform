<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Warehouse extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId;

    protected $table = 'warehouses';

    /**
     * Mirrors the column defaults in the migration so a freshly created
     * model exposes them without a refresh() — resources read ->value on
     * these enum casts and threw on null (found on the first real run).
     */
    protected $attributes = [
        'status' => 'active',
    ];

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
