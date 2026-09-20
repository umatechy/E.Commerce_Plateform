<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Promotions\Models\Promotion;
use App\Domain\Promotions\Models\PromotionStatus;
use App\Domain\Promotions\Models\PromotionTargetScope;
use App\Domain\Promotions\Models\PromotionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Promotion> */
final class PromotionFactory extends Factory
{
    protected $model = Promotion::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true).' Promotion',
            'type' => PromotionType::Percentage,
            'target_scope' => PromotionTargetScope::Order,
            'status' => PromotionStatus::Active,
            'percentage_value' => 10,
            'requires_coupon' => false,
            'priority' => 0,
        ];
    }
}
