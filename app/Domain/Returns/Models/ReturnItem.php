<?php

declare(strict_types=1);

namespace App\Domain\Returns\Models;

use App\Domain\Orders\Models\OrderItem;
use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 09 §47 "Partial Returns": one order line in a return, with the
 * quantity asked for and, after inspection (§48), what became of it.
 *
 * @property int $id
 * @property int $store_id
 * @property int $return_request_id
 * @property int $order_item_id
 * @property int $quantity
 * @property int $resalable_quantity
 * @property int $damaged_quantity
 * @property int $rejected_quantity
 * @property ?string $inspection_note
 * @property int $refund_minor
 */
final class ReturnItem extends Model
{
    use BelongsToTenant;

    protected $table = 'return_request_items';

    protected $guarded = ['id'];

    /** @return BelongsTo<ReturnRequest, $this> */
    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** What the store accepted back (resalable or damaged): what a refund or replacement is owed for. */
    public function acceptedQuantity(): int
    {
        return $this->resalable_quantity + $this->damaged_quantity;
    }
}
