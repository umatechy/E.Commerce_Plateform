<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\Models\ShipmentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Shipment> */
final class ShipmentFactory extends Factory
{
    protected $model = Shipment::class;

    public function definition(): array
    {
        return [
            'carrier' => 'store_pickup',
            'status' => ShipmentStatus::Draft,
            'currency' => 'USD',
            'idempotency_key' => (string) fake()->unique()->uuid(),
        ];
    }
}
