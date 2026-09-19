<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shipping\Models\ShippingRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShippingRate> */
final class ShippingRateFactory extends Factory
{
    protected $model = ShippingRate::class;

    public function definition(): array
    {
        return ['currency' => 'USD', 'base_cost_minor' => 500];
    }
}
