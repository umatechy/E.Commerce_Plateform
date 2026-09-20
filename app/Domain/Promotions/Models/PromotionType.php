<?php

declare(strict_types=1);

namespace App\Domain\Promotions\Models;

/**
 * Module 14 §4 "Promotion Types" — only the 3 core, deterministic
 * types B9 implements (see docs/development/b9-inspection-findings.md
 * "Scope Decision"). Buy X Get Y, quantity tiers, customer-group/
 * segment, and payment-method discounts are explicitly deferred.
 */
enum PromotionType: string
{
    case Percentage = 'percentage';
    case FixedAmount = 'fixed_amount';
    case FreeShipping = 'free_shipping';
}
