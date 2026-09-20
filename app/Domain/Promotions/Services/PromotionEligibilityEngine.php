<?php

declare(strict_types=1);

namespace App\Domain\Promotions\Services;

use App\Domain\Orders\Models\Customer;
use App\Domain\Promotions\Exceptions\CouponNotEligibleException;
use App\Domain\Promotions\Models\Coupon;
use App\Domain\Promotions\Models\Promotion;
use App\Domain\Promotions\Models\PromotionTargetScope;
use App\Domain\Promotions\Models\PromotionType;
use App\Domain\Promotions\Models\PromotionUsage;
use Illuminate\Support\Carbon;

/**
 * Module 14 §56-57 "Promotion Eligibility Engine / Deterministic
 * Calculation". The ONLY place promotion eligibility and discount
 * amounts are decided — CartService (preview) and CheckoutService
 * (authoritative) both call this SAME method, never duplicating the
 * logic. Every input is server-loaded/authoritative; nothing here
 * trusts a client-supplied eligibility result or discount amount
 * (Non-Negotiable Rules #2-3).
 *
 * STACKING (Architectural Decision — see
 * docs/development/b9-inspection-findings.md): non-stacking. At most
 * ONE promotion applies, chosen by highest benefit; ties broken by
 * priority desc, then created_at asc — never database row order or
 * PHP iteration order (Module 14 §57's explicit requirement).
 */
final class PromotionEligibilityEngine
{
    /**
     * @param list<array{product_id: ?int, category_ids: list<int>, brand_id: ?int, line_total_minor: int}> $cartItems
     * @throws CouponNotEligibleException
     */
    public function evaluate(
        array $cartItems,
        int $subtotalMinor,
        string $currency,
        ?Customer $customer,
        ?string $couponCode,
        int $shippingCostMinor = 0,
    ): PromotionEvaluationResult {
        $now = Carbon::now();
        $candidates = [];

        foreach ($this->automaticPromotions() as $promotion) {
            if ($this->isEligible($promotion, $now, $subtotalMinor, $customer)) {
                $candidates[] = $this->priceCandidate($promotion, null, $cartItems, $subtotalMinor, $shippingCostMinor);
            }
        }

        if ($couponCode !== null && $couponCode !== '') {
            $coupon = $this->findEligibleCoupon($couponCode, $now, $subtotalMinor, $customer);
            $candidates[] = $this->priceCandidate($coupon->promotion, $coupon, $cartItems, $subtotalMinor, $shippingCostMinor);
        }

        if ($candidates === []) {
            return PromotionEvaluationResult::none();
        }

        // Deterministic selection: highest benefit, tie-break by
        // priority desc, then created_at asc (Module 14 §57).
        usort($candidates, function (PromotionEvaluationResult $a, PromotionEvaluationResult $b) {
            $benefitA = $a->freeShipping ? $this->shippingBenefitValue($a) : $a->discountAmountMinor;
            $benefitB = $b->freeShipping ? $this->shippingBenefitValue($b) : $b->discountAmountMinor;

            if ($benefitA !== $benefitB) {
                return $benefitB <=> $benefitA;
            }
            if ($a->promotion->priority !== $b->promotion->priority) {
                return $b->promotion->priority <=> $a->promotion->priority;
            }

            return $a->promotion->created_at <=> $b->promotion->created_at;
        });

        return $candidates[0];
    }

    private function shippingBenefitValue(PromotionEvaluationResult $result): int
    {
        // free_shipping's comparable benefit is the shipping cost it
        // waives — passed through as discountAmountMinor internally by
        // priceCandidate() even for free_shipping, purely for this
        // comparison; the CALLER (CheckoutService) is the one that
        // interprets `freeShipping: true` as "zero the shipping line,"
        // not this engine double-applying it to the cart subtotal.
        return $result->discountAmountMinor;
    }

    /** @return \Illuminate\Support\Collection<int, Promotion> */
    private function automaticPromotions(): \Illuminate\Support\Collection
    {
        return Promotion::query()->with('targets')->where('requires_coupon', false)->where('status', 'active')->get();
    }

    private function isEligible(Promotion $promotion, Carbon $now, int $subtotalMinor, ?Customer $customer): bool
    {
        if (! $promotion->isCurrentlyActive($now)) {
            return false;
        }
        if (! $promotion->hasRemainingGlobalUsage()) {
            return false;
        }
        if ($promotion->min_order_value_minor !== null && $subtotalMinor < $promotion->min_order_value_minor) {
            return false;
        }
        if ($promotion->customer_usage_limit !== null && $customer !== null) {
            $usedByCustomer = PromotionUsage::query()->where('promotion_id', $promotion->id)->where('customer_id', $customer->id)->count();
            if ($usedByCustomer >= $promotion->customer_usage_limit) {
                return false;
            }
        }

        return true;
    }

    /**
     * @throws CouponNotEligibleException
     */
    private function findEligibleCoupon(string $code, Carbon $now, int $subtotalMinor, ?Customer $customer): Coupon
    {
        $coupon = Coupon::query()->with('promotion.targets')
            ->where('code_normalized', Coupon::normalize($code))
            ->where('is_active', true)
            ->first();

        if ($coupon === null || ! $coupon->hasRemainingGlobalUsage()) {
            throw new CouponNotEligibleException();
        }

        $promotion = $coupon->promotion;

        if ($promotion === null || ! $this->isEligible($promotion, $now, $subtotalMinor, $customer)) {
            throw new CouponNotEligibleException();
        }

        if ($coupon->customer_usage_limit !== null && $customer !== null) {
            $usedByCustomer = PromotionUsage::query()->where('coupon_id', $coupon->id)->where('customer_id', $customer->id)->count();
            if ($usedByCustomer >= $coupon->customer_usage_limit) {
                throw new CouponNotEligibleException();
            }
        }

        return $coupon;
    }

    /**
     * @param list<array{product_id: ?int, category_ids: list<int>, brand_id: ?int, line_total_minor: int}> $cartItems
     */
    private function priceCandidate(Promotion $promotion, ?Coupon $coupon, array $cartItems, int $subtotalMinor, int $shippingCostMinor): PromotionEvaluationResult
    {
        if ($promotion->type === PromotionType::FreeShipping) {
            return new PromotionEvaluationResult($promotion, $coupon, $shippingCostMinor, freeShipping: true);
        }

        if ($promotion->target_scope === PromotionTargetScope::Order) {
            $amount = $this->calculateAmount($promotion, $subtotalMinor);

            return new PromotionEvaluationResult($promotion, $coupon, $amount, freeShipping: false);
        }

        // Line-targeted (product/category/brand): sum eligible items'
        // line totals as the base, discount EACH matching line
        // proportionally to its own share (Architectural Decision —
        // see inspection findings "Discount Allocation": line-targeted
        // promotions allocate directly, no cross-cart proportional
        // split needed since only matching lines are touched at all).
        $lineDiscounts = [];
        $eligibleBase = 0;

        foreach ($cartItems as $index => $item) {
            if ($this->itemMatchesTarget($promotion, $item)) {
                $eligibleBase += $item['line_total_minor'];
            }
        }

        if ($eligibleBase <= 0) {
            return new PromotionEvaluationResult($promotion, $coupon, 0, freeShipping: false);
        }

        $totalDiscount = $this->calculateAmount($promotion, $eligibleBase);

        foreach ($cartItems as $index => $item) {
            if ($this->itemMatchesTarget($promotion, $item) && $item['line_total_minor'] > 0) {
                // Proportional share of the (already-capped) total
                // discount, rounded down per line, remainder implicitly
                // absorbed by the last matching line so the sum exactly
                // equals $totalDiscount (never over-discounts).
                $share = intdiv($totalDiscount * $item['line_total_minor'], $eligibleBase);
                $lineDiscounts[$index] = $share;
            }
        }

        $allocated = array_sum($lineDiscounts);
        if ($allocated < $totalDiscount && $lineDiscounts !== []) {
            $lastKey = array_key_last($lineDiscounts);
            $lineDiscounts[$lastKey] += $totalDiscount - $allocated;
        }

        return new PromotionEvaluationResult($promotion, $coupon, $totalDiscount, freeShipping: false, lineDiscounts: $lineDiscounts);
    }

    private function calculateAmount(Promotion $promotion, int $baseMinor): int
    {
        $amount = match ($promotion->type) {
            PromotionType::Percentage => intdiv($baseMinor * $promotion->percentage_value, 100),
            PromotionType::FixedAmount => min($promotion->fixed_amount_minor, $baseMinor),
            PromotionType::FreeShipping => 0, // handled separately in priceCandidate()
        };

        if ($promotion->max_discount_minor !== null) {
            $amount = min($amount, $promotion->max_discount_minor);
        }

        // Non-Negotiable Rule #9/#10: never negative, never exceeds the eligible base.
        return max(0, min($amount, $baseMinor));
    }

    private function itemMatchesTarget(Promotion $promotion, array $item): bool
    {
        return match ($promotion->target_scope) {
            PromotionTargetScope::Product => $promotion->targets->contains(fn ($t) => $t->target_type === 'product' && $t->target_id === $item['product_id']),
            PromotionTargetScope::Category => $promotion->targets->contains(fn ($t) => $t->target_type === 'category' && in_array($t->target_id, $item['category_ids'], true)),
            PromotionTargetScope::Brand => $promotion->targets->contains(fn ($t) => $t->target_type === 'brand' && $t->target_id === $item['brand_id']),
            PromotionTargetScope::Order => true,
        };
    }
}
