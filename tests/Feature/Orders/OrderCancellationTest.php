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
use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B5 — Order state machine + cancellation (Module 09 §15, §41-44).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class OrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    private function orderFor(Store $store, User $owner, Product $product, int $qty = 2): Order
    {
        $orderId = $this->actingAs($owner)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => $qty]],
            'guest_name' => 'Jane', 'guest_email' => 'jane@example.com',
            'idempotency_key' => 'cancel-test-'.uniqid(),
        ])->json('data.id');

        return Order::query()->where('public_id', $orderId)->firstOrFail();
    }

    private function setUp2(): array
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create();
        $package->entitlements()->create(['key' => 'orders.basic', 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $role = Role::factory()->for($store)->create(['slug' => 'owner']);
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $role->id, 'status' => 'active']);
        $product = Product::factory()->for($store)->create(['price_minor' => 1000, 'currency' => 'USD']);
        $warehouse = Warehouse::query()->where('store_id', $store->id)->where('is_default', true)->firstOrFail();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['product_id' => $product->id]);
        $this->app->make(TenantContext::class)->resolveToStore($store->id);
        $this->app->make(InventoryService::class)->setOpeningStock($inventory, 50, 'Init', actorId: $owner->id, idempotencyKey: 'o-'.$store->id);

        return [$store, $owner, $product];
    }

    public function test_confirmed_order_can_be_cancelled(): void
    {
        [$store, $owner, $product] = $this->setUp2();
        $order = $this->orderFor($store, $owner, $product);

        $response = $this->actingAs($owner)->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'customer_request']);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'cancelled');
    }

    public function test_cancelling_an_order_releases_its_reserved_stock(): void
    {
        [$store, $owner, $product] = $this->setUp2();
        $order = $this->orderFor($store, $owner, $product, qty: 5);

        $inventoryBefore = Inventory::query()->where('store_id', $store->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(5, $inventoryBefore->reserved);

        $this->actingAs($owner)->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'customer_request'])->assertOk();

        $this->assertSame(0, $inventoryBefore->fresh()->reserved);
    }

    public function test_cancellation_records_reason_actor_and_timestamp(): void
    {
        [$store, $owner, $product] = $this->setUp2();
        $order = $this->orderFor($store, $owner, $product);

        $this->actingAs($owner)->postJson("/api/v1/orders/{$order->id}/cancel", [
            'reason' => 'out_of_stock', 'note' => 'Vendor could not supply',
        ])->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('out_of_stock', $fresh->cancellation_reason->value);
        $this->assertSame($owner->id, $fresh->cancelled_by);
        $this->assertNotNull($fresh->cancelled_at);
    }

    public function test_cancellation_writes_a_timeline_event(): void
    {
        [$store, $owner, $product] = $this->setUp2();
        $order = $this->orderFor($store, $owner, $product);

        $this->actingAs($owner)->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'customer_request'])->assertOk();

        $this->assertDatabaseHas('order_timeline_events', [
            'order_id' => $order->id, 'event_type' => 'status_changed', 'to_status' => 'cancelled',
        ]);
    }

    public function test_shipped_order_cannot_be_cancelled_directly(): void
    {
        [$store, $owner, $product] = $this->setUp2();
        $order = $this->orderFor($store, $owner, $product);

        // Force the order into a non-cancellable state directly for this
        // test (no Fulfillment/Shipping module exists yet to reach
        // "shipped" through the real workflow — see B5 scope decision).
        $order->update(['status' => OrderStatus::Shipped]);

        $response = $this->actingAs($owner)->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'customer_request']);

        $response->assertStatus(422)->assertJsonPath('code', 'cancellation_not_allowed');
    }

    public function test_already_cancelled_order_cannot_be_cancelled_again(): void
    {
        [$store, $owner, $product] = $this->setUp2();
        $order = $this->orderFor($store, $owner, $product);

        $this->actingAs($owner)->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'customer_request'])->assertOk();
        $response = $this->actingAs($owner)->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'customer_request']);

        $response->assertStatus(422)->assertJsonPath('code', 'cancellation_not_allowed');
    }

    public function test_cancellation_requires_permission(): void
    {
        [$store, $owner, $product] = $this->setUp2();
        $order = $this->orderFor($store, $owner, $product);

        $role = Role::factory()->for($store)->create(['slug' => 'viewer-only']);
        $staff = User::factory()->create();
        $store->users()->attach($staff, ['role_id' => $role->id, 'status' => 'active']);

        $response = $this->actingAs($staff)->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'customer_request']);

        $response->assertStatus(403);
    }
}
