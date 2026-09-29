<?php

declare(strict_types=1);

namespace App\Domain\Cart\Models;

use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module 11 §5-9. Deliberately "dumb" like every other core-state
 * model in this codebase (Inventory, Order) — CartService is the only
 * writer of `status`; no controller sets it directly.
 */
final class Cart extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId;

    protected $table = 'carts';

    /**
     * Mirrors the column defaults in the migration so a freshly created
     * model exposes them without a refresh() — resources read ->value on
     * these enum casts and threw on null (found on the first real run).
     */
    protected $attributes = [
        'status' => 'active',
    ];

    protected $fillable = [
        'store_id', 'customer_id', 'guest_token', 'status', 'currency', 'coupon_code',
        'shipping_address_snapshot', 'billing_address_snapshot',
        'expires_at', 'converted_at', 'converted_order_id', 'abandoned_marketing_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CartStatus::class,
            'shipping_address_snapshot' => 'array',
            'billing_address_snapshot' => 'array',
            'expires_at' => 'datetime',
            'converted_at' => 'datetime',
            'abandoned_marketing_notified_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function convertedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'converted_order_id');
    }

    public function isGuestCart(): bool
    {
        return $this->customer_id === null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
