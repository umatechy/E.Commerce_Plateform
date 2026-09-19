<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B4 — Inventory record CRUD + stock balance (Module 08 §6-8).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Store $store): User
    {
        $role = Role::factory()->for($store)->create(['slug' => 'owner']);
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_owner_can_create_an_inventory_record_for_a_product(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $warehouse = Warehouse::factory()->for($store)->create(['code' => 'w1']);
        $product = Product::factory()->for($store)->create();

        $response = $this->actingAs($owner)->postJson('/api/v1/inventory', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('inventories', [
            'store_id' => $store->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        ]);
    }

    public function test_creating_duplicate_inventory_record_returns_the_existing_one(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $warehouse = Warehouse::factory()->for($store)->create(['code' => 'w1']);
        $product = Product::factory()->for($store)->create();

        $first = $this->actingAs($owner)->postJson('/api/v1/inventory', [
            'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        ])->json('data.id');

        $second = $this->actingAs($owner)->postJson('/api/v1/inventory', [
            'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        ])->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Inventory::query()->where('warehouse_id', $warehouse->id)->count());
    }

    public function test_available_equals_on_hand_minus_reserved(): void
    {
        $store = Store::factory()->create();
        $warehouse = Warehouse::factory()->for($store)->create();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['on_hand' => 100, 'reserved' => 20]);

        $this->assertSame(80, $inventory->available());
    }

    public function test_inventory_requires_either_product_or_variant(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $warehouse = Warehouse::factory()->for($store)->create();

        $response = $this->actingAs($owner)->postJson('/api/v1/inventory', ['warehouse_id' => $warehouse->id]);

        $response->assertStatus(422);
    }

    public function test_low_stock_flag_reflects_reorder_point(): void
    {
        $store = Store::factory()->create();
        $warehouse = Warehouse::factory()->for($store)->create();
        $inventory = Inventory::factory()->for($store)->for($warehouse)
            ->create(['on_hand' => 5, 'reserved' => 0, 'reorder_point' => 10]);

        $this->assertTrue($inventory->isLowStock());
    }
}
