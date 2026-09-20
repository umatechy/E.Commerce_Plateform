<?php

declare(strict_types=1);

namespace Tests\Feature\Promotions;

use App\Domain\Orders\Models\Customer;
use App\Domain\Promotions\Exceptions\CouponNotEligibleException;
use App\Domain\Promotions\Models\Coupon;
use App\Domain\Promotions\Models\Promotion;
use App\Domain\Promotions\Models\PromotionUsage;
use App\Domain\Promotions\Services\PromotionEligibilityEngine;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B9 — Coupon normalization, validation, usage limits (Module 14
 * §23-26/§42-44).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class CouponTest extends TestCase
{
    use RefreshDatabase;

    public function test_coupon_code_is_normalized_for_comparison(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $promotion = Promotion::factory()->for($store)->create(['requires_coupon' => true]);
        Coupon::factory()->for($store)->for($promotion)->create(['code' => 'Welcome10', 'code_normalized' => 'WELCOME10']);

        $result = app(PromotionEligibilityEngine::class)->evaluate([], 10000, 'USD', null, ' welcome10 ');

        $this->assertTrue($result->hasPromotion());
    }

    public function test_inactive_coupon_is_rejected(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $promotion = Promotion::factory()->for($store)->create(['requires_coupon' => true]);
        Coupon::factory()->for($store)->for($promotion)->create(['code' => 'DEAD10', 'code_normalized' => 'DEAD10', 'is_active' => false]);

        $this->expectException(CouponNotEligibleException::class);
        app(PromotionEligibilityEngine::class)->evaluate([], 10000, 'USD', null, 'DEAD10');
    }

    public function test_coupon_at_global_usage_limit_is_rejected(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $promotion = Promotion::factory()->for($store)->create(['requires_coupon' => true]);
        Coupon::factory()->for($store)->for($promotion)->create(['code' => 'MAXED', 'code_normalized' => 'MAXED', 'usage_limit' => 1, 'used_count' => 1]);

        $this->expectException(CouponNotEligibleException::class);
        app(PromotionEligibilityEngine::class)->evaluate([], 10000, 'USD', null, 'MAXED');
    }

    public function test_customer_who_already_used_coupon_up_to_their_limit_is_rejected(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $promotion = Promotion::factory()->for($store)->create(['requires_coupon' => true]);
        $coupon = Coupon::factory()->for($store)->for($promotion)->create(['code' => 'ONCE', 'code_normalized' => 'ONCE', 'customer_usage_limit' => 1]);
        $customer = Customer::factory()->for($store)->create();
        PromotionUsage::query()->create([
            'store_id' => $store->id, 'promotion_id' => $promotion->id, 'coupon_id' => $coupon->id,
            'customer_id' => $customer->id, 'order_id' => \App\Domain\Orders\Models\Order::factory()->for($store)->create()->id,
            'discount_amount_minor' => 100, 'currency' => 'USD',
        ]);

        $this->expectException(CouponNotEligibleException::class);
        app(PromotionEligibilityEngine::class)->evaluate([], 10000, 'USD', $customer, 'ONCE');
    }

    public function test_coupon_codes_are_unique_per_store_not_globally(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($storeA->id);
        $promotionA = Promotion::factory()->for($storeA)->create(['requires_coupon' => true]);
        Coupon::factory()->for($storeA)->for($promotionA)->create(['code' => 'SHARED', 'code_normalized' => 'SHARED']);

        app(TenantContext::class)->resolveToStore($storeB->id);
        $promotionB = Promotion::factory()->for($storeB)->create(['requires_coupon' => true]);
        // No exception — Store B can independently use the same code (Module 14 §25).
        Coupon::factory()->for($storeB)->for($promotionB)->create(['code' => 'SHARED', 'code_normalized' => 'SHARED']);

        $this->assertDatabaseCount('coupons', 2);
    }
}
