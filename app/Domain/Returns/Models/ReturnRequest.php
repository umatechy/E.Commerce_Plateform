<?php

declare(strict_types=1);

namespace App\Domain\Returns\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module 09 §45 "Return Request". Like Order, a "dumb" model: its status
 * and money are written only by ReturnService (through
 * ReturnStateMachine), never by a controller.
 *
 * @property int $id
 * @property string $public_id
 * @property int $store_id
 * @property int $order_id
 * @property ?int $customer_id
 * @property string $return_number
 * @property ReturnStatus $status
 * @property ReturnResolution $resolution
 * @property ReturnReason $reason
 * @property ?string $description
 * @property string $requested_by
 * @property ?string $decision_note
 * @property ?ReturnMethod $return_method
 * @property ?string $return_shipping_paid_by
 * @property ?string $return_carrier
 * @property ?string $return_tracking_number
 * @property ?int $warehouse_id
 * @property string $currency
 * @property int $items_refund_minor
 * @property int $shipping_refund_minor
 * @property int $restocking_fee_minor
 * @property int $refund_total_minor
 * @property int $refunded_minor
 * @property string $refund_method
 * @property int $refunded_credit_minor
 * @property ?int $refund_transaction_id
 * @property ?int $replacement_order_id
 * @property ?\Illuminate\Support\Carbon $decided_at
 * @property ?\Illuminate\Support\Carbon $shipped_back_at
 * @property ?\Illuminate\Support\Carbon $received_at
 * @property ?\Illuminate\Support\Carbon $inspected_at
 * @property ?\Illuminate\Support\Carbon $refunded_at
 * @property ?\Illuminate\Support\Carbon $completed_at
 * @property ?\Illuminate\Support\Carbon $cancelled_at
 * @property \Illuminate\Support\Carbon $created_at
 */
final class ReturnRequest extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $table = 'return_requests';

    protected $guarded = ['id', 'public_id'];

    protected $attributes = ['status' => 'requested', 'refund_method' => 'payment'];

    protected function casts(): array
    {
        return [
            'status' => ReturnStatus::class,
            'resolution' => ReturnResolution::class,
            'reason' => ReturnReason::class,
            'return_method' => ReturnMethod::class,
            'decided_at' => 'datetime',
            'shipped_back_at' => 'datetime',
            'received_at' => 'datetime',
            'inspected_at' => 'datetime',
            'refunded_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<ReturnItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ReturnItem::class);
    }

    /** @return HasMany<ReturnPhoto, $this> Module 09 §45 "Images" (Phase B34) */
    public function photos(): HasMany
    {
        return $this->hasMany(ReturnPhoto::class)->orderBy('id');
    }

    /** @return BelongsTo<Order, $this> */
    public function replacementOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'replacement_order_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
