<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Orders\Models\Order;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B5 — Module 09 §3 "Tenant Isolation".
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class OrderTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function entitledOwner(Store $store): User
    {
        $package = Package::factory()->create();
        $package->entitlements()->create(['key' => 'orders.basic', 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $role = $this->systemRole($store, 'owner');
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $role->id, 'status' => 'active']);

        return $owner;
    }

    public function test_store_a_cannot_read_store_bs_order(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->entitledOwner($storeA);
        $orderB = Order::factory()->for($storeB)->create();

        $this->actingAs($ownerA)->getJson("/api/v1/orders/{$orderB->id}")->assertStatus(404);
    }

    public function test_store_a_cannot_cancel_store_bs_order(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->entitledOwner($storeA);
        $orderB = Order::factory()->for($storeB)->create();

        $response = $this->actingAs($ownerA)->postJson("/api/v1/orders/{$orderB->id}/cancel", ['reason' => 'customer_request']);

        $response->assertStatus(404);
    }

    public function test_store_a_cannot_view_store_bs_order_timeline(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->entitledOwner($storeA);
        $orderB = Order::factory()->for($storeB)->create();

        $this->actingAs($ownerA)->getJson("/api/v1/orders/{$orderB->id}/timeline")->assertStatus(404);
    }

    public function test_order_listing_never_includes_another_stores_orders(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->entitledOwner($storeA);
        Order::factory()->for($storeB)->create(['order_number' => 'ORD-999999']);

        $response = $this->actingAs($ownerA)->getJson('/api/v1/orders');

        $response->assertOk();
        $response->assertJsonMissing(['order_number' => 'ORD-999999']);
    }

    public function test_store_a_cannot_create_an_order_referencing_store_bs_product(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->entitledOwner($storeA);
        $productB = Product::factory()->for($storeB)->create();

        $response = $this->actingAs($ownerA)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $productB->id, 'quantity' => 1]],
            'guest_name' => 'X', 'guest_email' => 'x@example.com',
            'idempotency_key' => 'cross-tenant-order',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('items');
    }
}
