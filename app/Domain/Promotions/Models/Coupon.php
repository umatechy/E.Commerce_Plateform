<?php

declare(strict_types=1);

namespace App\Domain\Promotions\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Coupon extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'coupons';

    protected $fillable = ['store_id', 'promotion_id', 'code', 'code_normalized', 'is_active', 'usage_limit', 'used_count', 'customer_usage_limit'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function hasRemainingGlobalUsage(): bool
    {
        return $this->usage_limit === null || $this->used_count < $this->usage_limit;
    }

    /** Module 14 §24 — codes are always normalized for comparison; see CouponService::normalize(). */
    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }
}
