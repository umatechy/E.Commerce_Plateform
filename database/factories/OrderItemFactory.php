<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderItem> */
final class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        return [
            'product_name_snapshot' => fake()->words(3, true),
            'quantity' => 1,
            'unit_price_minor' => 1000,
            'line_total_minor' => 1000,
        ];
    }
}
