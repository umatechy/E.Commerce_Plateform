<?php

declare(strict_types=1);

namespace App\Domain\Promotions\Models;

use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only — never updated/deleted by application code (Module 14 §69 audit requirement). */
final class PromotionUsage extends Model
{
    use BelongsToTenant;

    protected $table = 'promotion_usages';

    // created_at only (see migration). UPDATED_AT = null keeps Eloquent
    // filling created_at itself, so a just-created row exposes it
    // without a refresh (resources call ->toIso8601String() on it).
    public const UPDATED_AT = null;

    protected $fillable = ['store_id', 'promotion_id', 'coupon_id', 'customer_id', 'order_id', 'discount_amount_minor', 'currency'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<Promotion, $this> */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
