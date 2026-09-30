<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Models;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Orders\Models\Order;
use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module 13 §47-49. "Dumb" like every other core-state model —
 * ShipmentService is the only writer of `status`.
 */
final class Shipment extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId;

    protected $table = 'shipments';

    /**
     * Mirrors the column defaults in the migration so a freshly created
     * model exposes them without a refresh() — resources read ->value on
     * these enum casts and threw on null (found on the first real run).
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    protected $fillable = [
        'store_id', 'order_id', 'warehouse_id', 'shipping_method_id', 'pickup_location_id',
        'carrier', 'status', 'tracking_number', 'label_url', 'shipping_cost_minor', 'currency',
        'estimated_delivery_at', 'shipped_at', 'delivered_at', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'estimated_delivery_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return HasMany<ShipmentItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    /** @return HasMany<ShipmentTrackingEvent, $this> */
    public function trackingEvents(): HasMany
    {
        return $this->hasMany(ShipmentTrackingEvent::class);
    }
}
