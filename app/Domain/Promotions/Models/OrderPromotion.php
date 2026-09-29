<?php

declare(strict_types=1);

namespace App\Domain\Promotions\Models;

use App\Domain\Orders\Models\Order;
use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable historical snapshot (Module 14 §58-59) — never updated after creation. */
final class OrderPromotion extends Model
{
    use BelongsToTenant;

    protected $table = 'order_promotions';

    // created_at only (see migration). UPDATED_AT = null keeps Eloquent
    // filling created_at itself, so a just-created row exposes it
    // without a refresh (resources call ->toIso8601String() on it).
    public const UPDATED_AT = null;

    protected $fillable = ['store_id', 'order_id', 'promotion_id', 'promotion_name_snapshot', 'promotion_type_snapshot', 'coupon_code_snapshot', 'discount_amount_minor', 'currency'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
