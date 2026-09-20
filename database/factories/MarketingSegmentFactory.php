<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Marketing\Models\MarketingSegment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MarketingSegment> */
final class MarketingSegmentFactory extends Factory
{
    protected $model = MarketingSegment::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true).' Segment',
            'rules' => [['field' => 'total_orders_count', 'operator' => '>=', 'value' => 1]],
        ];
    }
}
