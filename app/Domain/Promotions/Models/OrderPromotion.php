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

    public $timestamps = false; // created_at only, see migration

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
