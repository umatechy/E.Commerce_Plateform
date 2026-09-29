<?php

declare(strict_types=1);

namespace App\Domain\Promotions\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module 14 §4-22. "Dumb" like every other core-state model —
 * PromotionService is the only writer of `used_count`.
 */
final class Promotion extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId;

    protected $table = 'promotions';

    /**
     * Mirrors the column defaults in the migration so a freshly created
     * model exposes them without a refresh() — resources read ->value on
     * these enum casts and threw on null (found on the first real run).
     */
    protected $attributes = [
        'target_scope' => 'order',
        'status' => 'draft',
    ];

    protected $fillable = [
        'store_id', 'name', 'type', 'target_scope', 'status',
        'percentage_value', 'fixed_amount_minor', 'currency',
        'min_order_value_minor', 'max_discount_minor', 'requires_coupon',
        'priority', 'usage_limit', 'used_count', 'customer_usage_limit',
        'starts_at', 'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => PromotionType::class,
            'target_scope' => PromotionTargetScope::class,
            'status' => PromotionStatus::class,
            'requires_coupon' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function targets(): HasMany
    {
        return $this->hasMany(PromotionTarget::class);
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(PromotionUsage::class);
    }

    /**
     * Module 14 §21-22: combines status + date range into one
     * authoritative check — Scheduled/Expired are DERIVED here rather
     * than stored, so they can never drift out of sync with the date
     * columns (see PromotionStatus's docblock).
     */
    public function isCurrentlyActive(\DateTimeInterface $now): bool
    {
        if ($this->status !== PromotionStatus::Active) {
            return false;
        }

        if ($this->starts_at !== null && $now < $this->starts_at) {
            return false;
        }

        if ($this->ends_at !== null && $now > $this->ends_at) {
            return false;
        }

        return true;
    }

    public function hasRemainingGlobalUsage(): bool
    {
        return $this->usage_limit === null || $this->used_count < $this->usage_limit;
    }
}
