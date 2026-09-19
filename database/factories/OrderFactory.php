<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
final class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'order_number' => 'ORD-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'guest_name' => fake()->name(),
            'guest_email' => fake()->unique()->safeEmail(),
            'status' => OrderStatus::Confirmed,
            'currency' => 'USD',
            'subtotal_minor' => 1000,
            'grand_total_minor' => 1000,
            'idempotency_key' => (string) fake()->unique()->uuid(),
        ];
    }
}
