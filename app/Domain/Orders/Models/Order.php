<?php

declare(strict_types=1);

namespace App\Domain\Orders\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module 09 §4 "Order Entity". Deliberately "dumb" like B4's Inventory
 * model — status transitions and totals are set ONLY by OrderService/
 * OrderStateMachine, never by direct `$order->update(['status' => ...])`
 * calls scattered through controllers (Module 09 Final Rule #11:
 * "confirmed orders must not be casually mutated").
 */
final class Order extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'orders';

    protected $fillable = [
        'store_id', 'order_number', 'customer_id',
        'guest_name', 'guest_email', 'guest_phone',
        'status', 'payment_status', 'fulfillment_status', 'source',
        'currency', 'subtotal_minor', 'discount_total_minor', 'tax_total_minor',
        'shipping_total_minor', 'grand_total_minor',
        'billing_address_snapshot', 'shipping_address_snapshot',
        'notes', 'idempotency_key',
        'cancellation_reason', 'cancellation_note', 'cancelled_by',
        'completed_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'fulfillment_status' => FulfillmentStatus::class,
            'source' => OrderSource::class,
            'cancellation_reason' => CancellationReason::class,
            'billing_address_snapshot' => 'array',
            'shipping_address_snapshot' => 'array',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function timelineEvents(): HasMany
    {
        return $this->hasMany(OrderTimelineEvent::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isGuestOrder(): bool
    {
        return $this->customer_id === null;
    }
}
