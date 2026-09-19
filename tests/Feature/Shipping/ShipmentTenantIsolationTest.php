<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase B8 — Shipment tenant isolation + staff/customer principal
 * boundary regression (Module 13 Step 23 items 1-5).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class ShipmentTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Store $store): User
    {
        $role = Role::factory()->for($store)->create(['slug' => 'owner']);
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_store_a_cannot_view_store_bs_shipment(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        $shipmentB = Shipment::factory()->for($storeB)->create([
            'order_id' => \App\Domain\Orders\Models\Order::factory()->for($storeB)->create()->id,
            'warehouse_id' => \App\Domain\Inventory\Models\Warehouse::query()->where('store_id', $storeB->id)->value('id'),
        ]);

        $this->actingAs($ownerA)->getJson("/api/v1/shipments/{$shipmentB->id}")->assertStatus(404);
    }

    public function test_shipment_listing_never_includes_another_stores_shipments(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        $shipmentB = Shipment::factory()->for($storeB)->create([
            'order_id' => \App\Domain\Orders\Models\Order::factory()->for($storeB)->create()->id,
            'warehouse_id' => \App\Domain\Inventory\Models\Warehouse::query()->where('store_id', $storeB->id)->value('id'),
            'tracking_number' => 'UNIQUE-TRACKING-B',
        ]);

        $response = $this->actingAs($ownerA)->getJson('/api/v1/shipments');

        $response->assertOk();
        $response->assertJsonMissing(['tracking_number' => 'UNIQUE-TRACKING-B']);
    }

    /** Regression of Phase B6/B7's critical customer/staff boundary — must still hold for the new Shipment routes. */
    public function test_customer_token_cannot_access_staff_shipment_routes(): void
    {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/shipments');

        $response->assertStatus(401);
    }

    public function test_shipping_config_management_requires_permission(): void
    {
        $store = Store::factory()->create();
        $role = Role::factory()->for($store)->create(['slug' => 'no-shipping-config']);
        $staff = User::factory()->create();
        $store->users()->attach($staff, ['role_id' => $role->id, 'status' => 'active']);

        $response = $this->actingAs($staff)->postJson('/api/v1/shipping/zones', ['name' => 'New Zone']);

        $response->assertStatus(403);
    }
}
