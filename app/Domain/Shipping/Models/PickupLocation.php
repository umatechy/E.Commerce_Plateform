<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Models;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PickupLocation extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId;

    protected $table = 'pickup_locations';

    /**
     * Mirrors the column defaults in the migration so a freshly created
     * model exposes them without a refresh() — resources read ->value on
     * these enum casts and threw on null (found on the first real run).
     */
    protected $attributes = [
        'status' => 'active',
    ];

    protected $fillable = ['store_id', 'warehouse_id', 'name', 'address', 'contact', 'instructions', 'status'];

    protected function casts(): array
    {
        return ['status' => PickupLocationStatus::class];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
