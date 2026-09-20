<?php

declare(strict_types=1);

namespace App\Domain\Promotions\Services;

use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Promotions\Exceptions\PromotionUsageLimitExceededException;
use App\Domain\Promotions\Models\OrderPromotion;
use App\Domain\Promotions\Models\PromotionUsage;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY code path that records promotion usage or writes an
 * order_promotions snapshot (mirrors every other domain service's
 * "service-only writes" pattern in this codebase).
 *
 * CONCURRENCY (Module 14 §44/§16 Step 16): usage-limit consumption
 * uses the SAME atomic-conditional-UPDATE strategy as every other
 * balance mutation since Phase B2 (UsageTrackingService) — a single
 * `UPDATE ... WHERE used_count < usage_limit OR usage_limit IS NULL`,
 * decided by the database via affected-row count, never a prior
 * SELECT-then-UPDATE.
 */
final class PromotionService
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Called by CheckoutService ONLY when the Order was genuinely just
     * created (never on an idempotent replay — see that class) and
     * ONLY when a promotion was actually selected by
     * PromotionEligibilityEngine.
     *
     * @throws PromotionUsageLimitExceededException
     */
    public function recordUsage(PromotionEvaluationResult $result, Order $order, ?Customer $customer): void
    {
        if (! $result->hasPromotion()) {
            return;
        }

        DB::transaction(function () use ($result, $order, $customer) {
            $this->atomicallyConsume('promotions', $result->promotion->id, $result->promotion->usage_limit);

            if ($result->coupon !== null) {
                $this->atomicallyConsume('coupons', $result->coupon->id, $result->coupon->usage_limit);
            }

            PromotionUsage::query()->create([
                'promotion_id' => $result->promotion->id,
                'coupon_id' => $result->coupon?->id,
                'customer_id' => $customer?->id,
                'order_id' => $order->id,
                'discount_amount_minor' => $result->discountAmountMinor,
                'currency' => $order->currency,
            ]);

            OrderPromotion::query()->create([
                'order_id' => $order->id,
                'promotion_id' => $result->promotion->id,
                'promotion_name_snapshot' => $result->promotion->name,
                'promotion_type_snapshot' => $result->promotion->type->value,
                'coupon_code_snapshot' => $result->coupon?->code,
                'discount_amount_minor' => $result->discountAmountMinor,
                'currency' => $order->currency,
            ]);
        });
    }

    /**
     * @throws PromotionUsageLimitExceededException
     */
    private function atomicallyConsume(string $table, int $id, ?int $usageLimit): void
    {
        $query = DB::table($table)->where('id', $id);

        if ($usageLimit !== null) {
            $query->whereRaw('used_count < ?', [$usageLimit]);
        }

        $affected = $query->update(['used_count' => DB::raw('used_count + 1'), 'updated_at' => now()]);

        if ($affected === 0) {
            throw new PromotionUsageLimitExceededException();
        }
    }
}
