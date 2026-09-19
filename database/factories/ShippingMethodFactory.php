<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Shipping\Models\ShippingMethodType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShippingMethod> */
final class ShippingMethodFactory extends Factory
{
    protected $model = ShippingMethod::class;

    public function definition(): array
    {
        return ['name' => 'Standard Delivery', 'type' => ShippingMethodType::FlatRate, 'is_active' => true];
    }
}
