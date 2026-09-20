<?php

declare(strict_types=1);

namespace Tests\Feature\Promotions;

use App\Domain\Promotions\Exceptions\CouponNotEligibleException;
use App\Domain\Promotions\Models\Promotion;
use App\Domain\Promotions\Models\PromotionTarget;
use App\Domain\Promotions\Services\PromotionEligibilityEngine;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B9 — Deterministic promotion calculation (Module 14 §5-6/§39-
 * 41/§56-57).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class PromotionEligibilityEngineTest extends TestCase
{
    use RefreshDatabase;

    private function setUpStore(): Store
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        return $store;
    }

    public function test_percentage_discount_calculated_on_subtotal(): void
    {
        $store = $this->setUpStore();
        Promotion::factory()->for($store)->create(['percentage_value' => 10]);

        $result = app(PromotionEligibilityEngine::class)->evaluate([], 10000, 'USD', null, null);

        $this->assertSame(1000, $result->discountAmountMinor);
    }

    public function test_fixed_amount_discount_never_exceeds_subtotal(): void
    {
        $store = $this->setUpStore();
        Promotion::factory()->for($store)->create(['type' => 'fixed_amount', 'fixed_amount_minor' => 5000, 'currency' => 'USD']);

        $result = app(PromotionEligibilityEngine::class)->evaluate([], 3000, 'USD', null, null);

        $this->assertSame(3000, $result->discountAmountMinor); // capped at the subtotal, never negative total
    }

    public function test_max_discount_caps_percentage_result(): void
    {
        $store = $this->setUpStore();
        Promotion::factory()->for($store)->create(['percentage_value' => 50, 'max_discount_minor' => 500]);

        $result = app(PromotionEligibilityEngine::class)->evaluate([], 10000, 'USD', null, null);

        $this->assertSame(500, $result->discountAmountMinor); // 50% of 10000 = 5000, capped at 500
    }

    public function test_minimum_order_value_blocks_ineligible_cart(): void
    {
        $store = $this->setUpStore();
        Promotion::factory()->for($store)->create(['min_order_value_minor' => 20000]);

        $result = app(PromotionEligibilityEngine::class)->evaluate([], 10000, 'USD', null, null);

        $this->assertFalse($result->hasPromotion());
    }

    public function test_expired_promotion_is_not_applied(): void
    {
        $store = $this->setUpStore();
        Promotion::factory()->for($store)->create(['ends_at' => now()->subDay()]);

        $result = app(PromotionEligibilityEngine::class)->evaluate([], 10000, 'USD', null, null);

        $this->assertFalse($result->hasPromotion());
    }

    public function test_not_yet_started_promotion_is_not_applied(): void
    {
        $store = $this->setUpStore();
        Promotion::factory()->for($store)->create(['starts_at' => now()->addDay()]);

        $result = app(PromotionEligibilityEngine::class)->evaluate([], 10000, 'USD', null, null);

        $this->assertFalse($result->hasPromotion());
    }

    public function test_product_targeted_promotion_only_discounts_matching_line(): void
    {
        $store = $this->setUpStore();
        $promotion = Promotion::factory()->for($store)->create(['target_scope' => 'product', 'percentage_value' => 10]);
        PromotionTarget::query()->create(['store_id' => $store->id, 'promotion_id' => $promotion->id, 'target_type' => 'product', 'target_id' => 42]);

        $cartItems = [
            ['product_id' => 42, 'category_ids' => [], 'brand_id' => null, 'line_total_minor' => 2000],
            ['product_id' => 99, 'category_ids' => [], 'brand_id' => null, 'line_total_minor' => 3000],
        ];

        $result = app(PromotionEligibilityEngine::class)->evaluate($cartItems, 5000, 'USD', null, null);

        $this->assertSame(200, $result->discountAmountMinor); // 10% of 2000 only
        $this->assertSame(200, $result->lineDiscounts[0]);
        $this->assertArrayNotHasKey(1, $result->lineDiscounts);
    }

    public function test_category_targeted_promotion_matches_any_item_in_category(): void
    {
        $store = $this->setUpStore();
        $promotion = Promotion::factory()->for($store)->create(['target_scope' => 'category', 'type' => 'fixed_amount', 'fixed_amount_minor' => 100, 'currency' => 'USD']);
        PromotionTarget::query()->create(['store_id' => $store->id, 'promotion_id' => $promotion->id, 'target_type' => 'category', 'target_id' => 7]);

        $cartItems = [['product_id' => 1, 'category_ids' => [7, 8], 'brand_id' => null, 'line_total_minor' => 2000]];

        $result = app(PromotionEligibilityEngine::class)->evaluate($cartItems, 2000, 'USD', null, null);

        $this->assertSame(100, $result->discountAmountMinor);
    }

    public function test_non_matching_item_gets_no_discount(): void
    {
        $store = $this->setUpStore();
        $promotion = Promotion::factory()->for($store)->create(['target_scope' => 'brand', 'percentage_value' => 20]);
        PromotionTarget::query()->create(['store_id' => $store->id, 'promotion_id' => $promotion->id, 'target_type' => 'brand', 'target_id' => 5]);

        $cartItems = [['product_id' => 1, 'category_ids' => [], 'brand_id' => 999, 'line_total_minor' => 2000]];

        $result = app(PromotionEligibilityEngine::class)->evaluate($cartItems, 2000, 'USD', null, null);

        $this->assertFalse($result->hasPromotion());
    }

    public function test_highest_benefit_wins_between_two_automatic_promotions(): void
    {
        $store = $this->setUpStore();
        Promotion::factory()->for($store)->create(['percentage_value' => 5]);
        Promotion::factory()->for($store)->create(['percentage_value' => 15]);

        $result = app(PromotionEligibilityEngine::class)->evaluate([], 10000, 'USD', null, null);

        $this->assertSame(1500, $result->discountAmountMinor); // the 15% one wins
    }

    public function test_priority_breaks_a_tie_between_equal_discounts(): void
    {
        $store = $this->setUpStore();
        Promotion::factory()->for($store)->create(['name' => 'Low Priority', 'percentage_value' => 10, 'priority' => 1]);
        $highPriority = Promotion::factory()->for($store)->create(['name' => 'High Priority', 'percentage_value' => 10, 'priority' => 10]);

        $result = app(PromotionEligibilityEngine::class)->evaluate([], 10000, 'USD', null, null);

        $this->assertSame('High Priority', $result->promotion->name);
    }

    public function test_unknown_coupon_code_throws_a_generic_exception(): void
    {
        $store = $this->setUpStore();

        $this->expectException(CouponNotEligibleException::class);
        app(PromotionEligibilityEngine::class)->evaluate([], 10000, 'USD', null, 'DOES-NOT-EXIST');
    }

    public function test_free_shipping_promotion_returns_shipping_cost_as_benefit(): void
    {
        $store = $this->setUpStore();
        Promotion::factory()->for($store)->create(['type' => 'free_shipping', 'percentage_value' => null]);

        $result = app(PromotionEligibilityEngine::class)->evaluate([], 10000, 'USD', null, null, shippingCostMinor: 500);

        $this->assertTrue($result->freeShipping);
    }
}
