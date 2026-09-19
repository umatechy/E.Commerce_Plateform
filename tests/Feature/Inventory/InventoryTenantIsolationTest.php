<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B4 — Module 08 §3/§73 "Tenant Isolation / Inventory Security".
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class InventoryTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Store $store): User
    {
        $role = Role::factory()->for($store)->create(['slug' => 'owner']);
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_store_a_cannot_read_store_bs_inventory(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        $warehouseB = Warehouse::factory()->for($storeB)->create();
        $inventoryB = Inventory::factory()->for($storeB)->for($warehouseB)->create();

        $this->actingAs($ownerA)->getJson("/api/v1/inventory/{$inventoryB->id}")->assertStatus(404);
    }

    public function test_store_a_cannot_adjust_store_bs_inventory(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        $warehouseB = Warehouse::factory()->for($storeB)->create();
        $inventoryB = Inventory::factory()->for($storeB)->for($warehouseB)->create(['on_hand' => 100]);

        $response = $this->actingAs($ownerA)->postJson("/api/v1/inventory/{$inventoryB->id}/adjust", [
            'quantity' => -50, 'reason' => 'Malicious attempt',
        ]);

        $response->assertStatus(404);
        $this->assertSame(100, $inventoryB->fresh()->on_hand);
    }

    public function test_store_a_cannot_reserve_store_bs_inventory(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        $warehouseB = Warehouse::factory()->for($storeB)->create();
        $inventoryB = Inventory::factory()->for($storeB)->for($warehouseB)->create(['on_hand' => 100]);

        $response = $this->actingAs($ownerA)->postJson("/api/v1/inventory/{$inventoryB->id}/reservations", [
            'quantity' => 10, 'idempotency_key' => 'cross-tenant-attempt',
        ]);

        $response->assertStatus(404);
    }

    public function test_store_a_cannot_view_store_bs_movement_history(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        $warehouseB = Warehouse::factory()->for($storeB)->create();
        $inventoryB = Inventory::factory()->for($storeB)->for($warehouseB)->create();

        $this->actingAs($ownerA)->getJson("/api/v1/inventory/{$inventoryB->id}/movements")->assertStatus(404);
    }

    public function test_store_a_cannot_create_inventory_against_store_bs_warehouse(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        $warehouseB = Warehouse::factory()->for($storeB)->create();
        $productA = \App\Domain\Catalog\Models\Product::factory()->for($storeA)->create();

        $response = $this->actingAs($ownerA)->postJson('/api/v1/inventory', [
            'warehouse_id' => $warehouseB->id, 'product_id' => $productA->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('warehouse_id');
    }

    public function test_store_a_cannot_create_inventory_against_store_bs_product(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        $warehouseA = Warehouse::factory()->for($storeA)->create(['code' => 'w-a']);
        $productB = \App\Domain\Catalog\Models\Product::factory()->for($storeB)->create();

        $response = $this->actingAs($ownerA)->postJson('/api/v1/inventory', [
            'warehouse_id' => $warehouseA->id, 'product_id' => $productB->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('product_id');
    }

    public function test_inventory_listing_never_includes_another_stores_records(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        $warehouseA = Warehouse::factory()->for($storeA)->create(['code' => 'w-a']);
        $warehouseB = Warehouse::factory()->for($storeB)->create();
        Inventory::factory()->for($storeA)->for($warehouseA)->create(['on_hand' => 1]);
        $inventoryB = Inventory::factory()->for($storeB)->for($warehouseB)->create(['on_hand' => 999]);

        $response = $this->actingAs($ownerA)->getJson('/api/v1/inventory');

        $response->assertOk();
        $response->assertJsonMissing(['id' => $inventoryB->public_id]);
    }
}
