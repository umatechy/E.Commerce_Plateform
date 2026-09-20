<?php

declare(strict_types=1);

namespace App\Domain\Promotions\Services;

use App\Domain\Promotions\Models\Coupon;
use App\Domain\Promotions\Models\Promotion;

/**
 * The ONE output shape of PromotionEligibilityEngine::evaluate() —
 * every field is already server-computed; nothing here is ever
 * re-derived from client input.
 */
final class PromotionEvaluationResult
{
    /**
     * @param array<int, int> $lineDiscounts cart-item-index => discount_minor (line-targeted promotions only — see inspection findings "Discount Allocation")
     */
    public function __construct(
        public readonly ?Promotion $promotion,
        public readonly ?Coupon $coupon,
        public readonly int $discountAmountMinor,
        public readonly bool $freeShipping,
        public readonly array $lineDiscounts = [],
    ) {}

    public static function none(): self
    {
        return new self(null, null, 0, false);
    }

    public function hasPromotion(): bool
    {
        return $this->promotion !== null;
    }
}
