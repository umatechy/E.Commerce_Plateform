<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module 08 §6 "Stock Quantity Model" / §8 "Inventory Record". This
 * model is intentionally "dumb" — it holds the current balance columns
 * only. ALL mutation goes through InventoryService's atomic conditional
 * UPDATE statements (see that class's docblock) — nothing here performs
 * `$inventory->on_hand += X; $inventory->save()`, which would reintroduce
 * exactly the read-modify-write race this milestone requires closing.
 */
final class Inventory extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId;

    protected $table = 'inventories';

    protected $fillable = [
        'store_id', 'warehouse_id', 'product_id', 'product_variant_id',
        'on_hand', 'reserved', 'incoming', 'reorder_point', 'reorder_quantity',
    ];

    protected function casts(): array
    {
        return [
            'on_hand' => 'integer',
            'reserved' => 'integer',
            'incoming' => 'integer',
        ];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return HasMany<StockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** @return HasMany<StockReservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    /**
     * Module 08 §6/§19: "AVAILABLE = ON_HAND - RESERVED - OTHER_COMMITTED_STOCK".
     * B4 documented simplification (no Orders/commitment concept exists
     * yet): AVAILABLE = ON_HAND - RESERVED. See
     * docs/architecture/b4-inventory.md "Stock Balance Calculation".
     */
    public function available(): int
    {
        return max(0, $this->on_hand - $this->reserved);
    }

    /** Module 08 §27 "Low Stock". */
    public function isLowStock(): bool
    {
        return $this->reorder_point !== null && $this->available() <= $this->reorder_point;
    }

    public function isOutOfStock(): bool
    {
        return $this->available() <= 0;
    }
}
