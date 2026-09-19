<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Warehouse> */
final class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        return [
            'name' => 'Main Warehouse',
            'code' => 'main',
            'status' => 'active',
            'is_default' => true,
        ];
    }
}
