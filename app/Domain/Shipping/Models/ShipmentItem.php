<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Models;

use App\Domain\Orders\Models\OrderItem;
use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ShipmentItem extends Model
{
    use BelongsToTenant;

    protected $table = 'shipment_items';

    protected $fillable = ['store_id', 'shipment_id', 'order_item_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    /** @return BelongsTo<Shipment, $this> */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
