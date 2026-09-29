<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Orders\Models\Order;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B5 — Order creation, server-authoritative pricing, snapshots,
 * order numbering, idempotency (Module 09 §19-24).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class OrderCreationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Store, 1: User, 2: Product} */
    private function setUpStoreWithProduct(int $stock = 100, ?int $maxMonthlyOrders = null): array
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create();
        $package->entitlements()->create([
            'key' => 'orders.basic', 'type' => EntitlementType::Feature, 'boolean_value' => true,
        ]);
        if ($maxMonthlyOrders !== null) {
            $package->entitlements()->create([
                'key' => 'max_monthly_orders', 'type' => EntitlementType::UsageLimit, 'limit_value' => $maxMonthlyOrders,
            ]);
        }
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);

        $role = $this->systemRole($store, 'owner');
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $role->id, 'status' => 'active']);

        $product = Product::factory()->for($store)->create(['price_minor' => 2500, 'currency' => 'USD']);

        $warehouse = Warehouse::query()->withoutTenantScope()->where('store_id', $store->id)->where('is_default', true)->firstOrFail();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['product_id' => $product->id]);

        $this->app->make(TenantContext::class)->resolveToStore($store->id);
        $this->app->make(InventoryService::class)->setOpeningStock($inventory, $stock, 'Initial stock', actorId: $owner->id, idempotencyKey: 'open-'.$store->id);

        return [$store, $owner, $product];
    }

    public function test_owner_can_create_an_order_and_it_is_immediately_confirmed(): void
    {
        [$store, $owner, $product] = $this->setUpStoreWithProduct();

        $response = $this->actingAs($owner)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'guest_name' => 'Jane Doe',
            'guest_email' => 'jane@example.com',
            'idempotency_key' => 'order-1',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'confirmed');
        $this->assertDatabaseHas('orders', ['store_id' => $store->id, 'grand_total_minor' => 5000]);
    }

    public function test_order_total_is_computed_server_side_and_client_total_is_ignored(): void
    {
        [$store, $owner, $product] = $this->setUpStoreWithProduct();

        $response = $this->actingAs($owner)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'grand_total_minor' => 1, // attempted tampering — not even a valid field name, must be ignored
            'guest_name' => 'Jane', 'guest_email' => 'jane@example.com',
            'idempotency_key' => 'order-tamper',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.grand_total_minor', 2500); // server-computed 1 x 2500, NOT the client's 1
    }

    public function test_order_item_snapshots_survive_later_product_changes(): void
    {
        [$store, $owner, $product] = $this->setUpStoreWithProduct();

        $order = $this->actingAs($owner)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'guest_name' => 'Jane', 'guest_email' => 'jane@example.com',
            'idempotency_key' => 'order-snapshot',
        ])->json('data.id');

        $product->update(['name' => 'Renamed Product', 'price_minor' => 99999]);

        $orderModel = Order::query()->where('public_id', $order)->firstOrFail();
        $this->assertSame(2500, $orderModel->items->first()->unit_price_minor);
        $this->assertNotSame('Renamed Product', $orderModel->items->first()->product_name_snapshot);
    }

    public function test_order_creation_fails_when_out_of_stock(): void
    {
        [$store, $owner, $product] = $this->setUpStoreWithProduct(stock: 1);

        $response = $this->actingAs($owner)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
            'guest_name' => 'Jane', 'guest_email' => 'jane@example.com',
            'idempotency_key' => 'order-oos',
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'insufficient_stock');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_creation_fails_without_feature_entitlement(): void
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create(); // no orders.basic entitlement
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $role = $this->systemRole($store, 'owner');
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $role->id, 'status' => 'active']);
        $product = Product::factory()->for($store)->create();

        $response = $this->actingAs($owner)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'guest_name' => 'Jane', 'guest_email' => 'jane@example.com',
            'idempotency_key' => 'order-no-feature',
        ]);

        $response->assertStatus(403);
    }

    public function test_order_creation_blocked_when_monthly_order_limit_reached(): void
    {
        [$store, $owner, $product] = $this->setUpStoreWithProduct(stock: 100, maxMonthlyOrders: 1);

        $this->actingAs($owner)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'guest_name' => 'A', 'guest_email' => 'a@example.com', 'idempotency_key' => 'order-first',
        ])->assertCreated();

        $response = $this->actingAs($owner)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'guest_name' => 'B', 'guest_email' => 'b@example.com', 'idempotency_key' => 'order-second',
        ]);

        $response->assertStatus(403)->assertJsonPath('code', 'usage_limit_exceeded');
    }

    public function test_empty_order_is_rejected(): void
    {
        [$store, $owner] = $this->setUpStoreWithProduct();

        $response = $this->actingAs($owner)->postJson('/api/v1/orders', [
            'items' => [],
            'guest_name' => 'Jane', 'guest_email' => 'jane@example.com',
            'idempotency_key' => 'order-empty',
        ]);

        $response->assertStatus(422);
    }

    public function test_duplicate_idempotency_key_returns_the_same_order_not_a_new_one(): void
    {
        [$store, $owner, $product] = $this->setUpStoreWithProduct();

        $first = $this->actingAs($owner)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'guest_name' => 'Jane', 'guest_email' => 'jane@example.com',
            'idempotency_key' => 'same-key',
        ])->json('data.id');

        $secondResponse = $this->actingAs($owner)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'guest_name' => 'Jane', 'guest_email' => 'jane@example.com',
            'idempotency_key' => 'same-key',
        ]);

        $secondResponse->assertOk(); // 200, not 201 — see OrderController::store()'s wasRecentlyCreated check
        $this->assertSame($first, $secondResponse->json('data.id'));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_order_numbers_are_sequential_and_unique_per_store(): void
    {
        [$store, $owner, $product] = $this->setUpStoreWithProduct();

        $first = $this->actingAs($owner)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'guest_name' => 'A', 'guest_email' => 'a@example.com', 'idempotency_key' => 'n1',
        ])->json('data.order_number');

        $second = $this->actingAs($owner)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'guest_name' => 'B', 'guest_email' => 'b@example.com', 'idempotency_key' => 'n2',
        ])->json('data.order_number');

        $this->assertSame('ORD-000001', $first);
        $this->assertSame('ORD-000002', $second);
    }

    public function test_order_reserves_stock_but_does_not_deduct_on_hand(): void
    {
        [$store, $owner, $product] = $this->setUpStoreWithProduct(stock: 10);

        $this->actingAs($owner)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'guest_name' => 'Jane', 'guest_email' => 'jane@example.com',
            'idempotency_key' => 'order-reserve',
        ])->assertCreated();

        $inventory = Inventory::query()->where('store_id', $store->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(10, $inventory->on_hand); // unchanged — B5 reserves, does not deduct (see architecture doc)
        $this->assertSame(3, $inventory->reserved);
    }
}
