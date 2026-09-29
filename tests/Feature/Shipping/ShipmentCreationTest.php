<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\PaymentStatus as OrderPaymentStatus;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Shipping\Exceptions\ShipmentQuantityExceedsOrderedException;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B8 — Shipment creation, quantity validation, inventory
 * fulfillment (reservation -> commit) integration, payment/fulfillment
 * boundary (Module 13 Steps 10-11/16-17).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class ShipmentCreationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Store, 1: Order, 2: User, 3: Warehouse} */
    private function paidOrderWithReservedStock(int $quantity = 5, int $stock = 10): array
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create();
        $package->entitlements()->create(['key' => 'orders.basic', 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $product = Product::factory()->for($store)->create(['price_minor' => 1000, 'currency' => 'USD']);
        $warehouse = Warehouse::query()->withoutTenantScope()->where('store_id', $store->id)->where('is_default', true)->firstOrFail();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['product_id' => $product->id]);

        app(TenantContext::class)->resolveToStore($store->id);
        app(InventoryService::class)->setOpeningStock($inventory, $stock, 'Init', actorId: $this->actorId(), idempotencyKey: 'open-'.$store->id);
        app(InventoryService::class)->reserve($inventory, $quantity, idempotencyKey: 'res-1', referenceType: 'order', referenceId: 999);

        $order = Order::factory()->for($store)->create(['payment_status' => OrderPaymentStatus::Paid]);
        $orderItem = OrderItem::factory()->for($order)->create([
            'store_id' => $store->id, 'product_id' => $product->id, 'quantity' => $quantity, 'unit_price_minor' => 1000, 'line_total_minor' => $quantity * 1000,
        ]);

        // Re-point the reservation at the REAL order id now that it exists.
        \App\Domain\Inventory\Models\StockReservation::query()->where('idempotency_key', 'res-1')->update(['reference_id' => $order->id]);

        $role = $this->systemRole($store, 'owner');
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $role->id, 'status' => 'active']);

        return [$store, $order, $owner, $warehouse, $orderItem, $inventory];
    }

    public function test_staff_can_create_a_shipment_for_a_paid_order(): void
    {
        [$store, $order, $owner, $warehouse, $orderItem] = $this->paidOrderWithReservedStock();

        $response = $this->actingAs($owner)->postJson('/api/v1/shipments', [
            'order_id' => $order->public_id, 'warehouse_id' => $warehouse->id, 'carrier' => 'store_pickup',
            'items' => [['order_item_id' => $orderItem->id, 'quantity' => 5]],
            'idempotency_key' => 'ship-1',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'ready');
    }

    public function test_shipment_creation_commits_the_reservation_and_deducts_on_hand(): void
    {
        [$store, $order, $owner, $warehouse, $orderItem, $inventory] = $this->paidOrderWithReservedStock(quantity: 5, stock: 10);

        $this->actingAs($owner)->postJson('/api/v1/shipments', [
            'order_id' => $order->public_id, 'warehouse_id' => $warehouse->id, 'carrier' => 'store_pickup',
            'items' => [['order_item_id' => $orderItem->id, 'quantity' => 5]],
            'idempotency_key' => 'ship-2',
        ])->assertCreated();

        $fresh = $inventory->fresh();
        $this->assertSame(5, $fresh->on_hand); // 10 - 5 committed
        $this->assertSame(0, $fresh->reserved); // fully converted
    }

    public function test_shipment_updates_order_fulfillment_status(): void
    {
        [$store, $order, $owner, $warehouse, $orderItem] = $this->paidOrderWithReservedStock(quantity: 5);

        $this->actingAs($owner)->postJson('/api/v1/shipments', [
            'order_id' => $order->public_id, 'warehouse_id' => $warehouse->id, 'carrier' => 'store_pickup',
            'items' => [['order_item_id' => $orderItem->id, 'quantity' => 5]],
            'idempotency_key' => 'ship-3',
        ])->assertCreated();

        $this->assertSame('fulfilled', $order->fresh()->fulfillment_status->value);
    }

    public function test_partial_shipment_marks_order_partially_fulfilled(): void
    {
        [$store, $order, $owner, $warehouse, $orderItem] = $this->paidOrderWithReservedStock(quantity: 5);

        $this->actingAs($owner)->postJson('/api/v1/shipments', [
            'order_id' => $order->public_id, 'warehouse_id' => $warehouse->id, 'carrier' => 'store_pickup',
            'items' => [['order_item_id' => $orderItem->id, 'quantity' => 2]],
            'idempotency_key' => 'ship-partial',
        ])->assertCreated();

        $this->assertSame('partially_fulfilled', $order->fresh()->fulfillment_status->value);
    }

    public function test_shipment_quantity_cannot_exceed_ordered_quantity(): void
    {
        [$store, $order, $owner, $warehouse, $orderItem] = $this->paidOrderWithReservedStock(quantity: 5);

        $response = $this->actingAs($owner)->postJson('/api/v1/shipments', [
            'order_id' => $order->public_id, 'warehouse_id' => $warehouse->id, 'carrier' => 'store_pickup',
            'items' => [['order_item_id' => $orderItem->id, 'quantity' => 999]],
            'idempotency_key' => 'ship-over',
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'quantity_exceeds_ordered');
    }

    public function test_a_second_shipment_cannot_exceed_the_remaining_unshipped_quantity(): void
    {
        [$store, $order, $owner, $warehouse, $orderItem] = $this->paidOrderWithReservedStock(quantity: 5);

        $this->actingAs($owner)->postJson('/api/v1/shipments', [
            'order_id' => $order->public_id, 'warehouse_id' => $warehouse->id, 'carrier' => 'store_pickup',
            'items' => [['order_item_id' => $orderItem->id, 'quantity' => 3]],
            'idempotency_key' => 'ship-first-partial',
        ])->assertCreated();

        $response = $this->actingAs($owner)->postJson('/api/v1/shipments', [
            'order_id' => $order->public_id, 'warehouse_id' => $warehouse->id, 'carrier' => 'store_pickup',
            'items' => [['order_item_id' => $orderItem->id, 'quantity' => 3]], // only 2 remain
            'idempotency_key' => 'ship-second-too-much',
        ]);

        $response->assertStatus(422)->assertJsonPath('remaining', 2);
    }

    public function test_duplicate_idempotency_key_returns_the_same_shipment(): void
    {
        [$store, $order, $owner, $warehouse, $orderItem] = $this->paidOrderWithReservedStock(quantity: 5);
        $payload = [
            'order_id' => $order->public_id, 'warehouse_id' => $warehouse->id, 'carrier' => 'store_pickup',
            'items' => [['order_item_id' => $orderItem->id, 'quantity' => 5]],
            'idempotency_key' => 'ship-dup',
        ];

        $first = $this->actingAs($owner)->postJson('/api/v1/shipments', $payload);
        $second = $this->actingAs($owner)->postJson('/api/v1/shipments', $payload);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('shipments', 1);
    }

    public function test_fulfillment_is_blocked_when_online_payment_is_not_yet_paid(): void
    {
        [$store, $order, $owner, $warehouse, $orderItem] = $this->paidOrderWithReservedStock(quantity: 5);
        $order->update(['payment_status' => OrderPaymentStatus::Pending]);

        $response = $this->actingAs($owner)->postJson('/api/v1/shipments', [
            'order_id' => $order->public_id, 'warehouse_id' => $warehouse->id, 'carrier' => 'store_pickup',
            'items' => [['order_item_id' => $orderItem->id, 'quantity' => 5]],
            'idempotency_key' => 'ship-unpaid',
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'fulfillment_not_allowed');
    }

    public function test_fulfillment_requires_permission(): void
    {
        [$store, $order, $owner, $warehouse, $orderItem] = $this->paidOrderWithReservedStock(quantity: 5);
        $role = Role::factory()->for($store)->create(['slug' => 'no-fulfill']);
        $staff = User::factory()->create();
        $store->users()->attach($staff, ['role_id' => $role->id, 'status' => 'active']);

        $response = $this->actingAs($staff)->postJson('/api/v1/shipments', [
            'order_id' => $order->public_id, 'warehouse_id' => $warehouse->id, 'carrier' => 'store_pickup',
            'items' => [['order_item_id' => $orderItem->id, 'quantity' => 5]],
            'idempotency_key' => 'ship-no-perm',
        ]);

        $response->assertStatus(403);
    }
}
