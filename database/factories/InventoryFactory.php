<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Inventory> */
final class InventoryFactory extends Factory
{
    protected $model = Inventory::class;

    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            // product_id / product_variant_id are deliberately NOT
            // defaulted to a nested factory here — creating a Product
            // via its own factory requires a resolved TenantContext
            // (BelongsToTenant's creating hook), which is not
            // guaranteed to be set at the point an Inventory factory
            // runs (most Inventory tests resolve TenantContext AFTER
            // creating fixtures via ->for($store), not before). Tests
            // that need a real product pass `product_id` explicitly
            // (e.g. `Product::factory()->for($store)->create()->id`).
            'product_id' => null,
            'product_variant_id' => null,
            'on_hand' => 0,
            'reserved' => 0,
        ];
    }
}
