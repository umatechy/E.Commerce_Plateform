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
        // StoreObserver already seeds every store's default 'main'
        // warehouse, so a factory row must use its own code and must not
        // claim to be a second default (uniq_warehouses_store_id_code).
        $code = 'wh-'.fake()->unique()->numerify('####');

        return [
            'name' => 'Warehouse '.strtoupper($code),
            'code' => $code,
            'status' => 'active',
            'is_default' => false,
        ];
    }
}
