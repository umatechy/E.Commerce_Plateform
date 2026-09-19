<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shipping\Models\ShippingZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShippingZone> */
final class ShippingZoneFactory extends Factory
{
    protected $model = ShippingZone::class;

    public function definition(): array
    {
        return ['name' => fake()->city().' Zone', 'is_active' => true];
    }
}
