<?php

declare(strict_types=1);

namespace App\Domain\Promotions\Models;

/** Module 14 §7-11 — what a promotion applies to. */
enum PromotionTargetScope: string
{
    case Order = 'order'; // whole cart/order — no promotion_targets rows needed
    case Product = 'product';
    case Category = 'category';
    case Brand = 'brand';
}
