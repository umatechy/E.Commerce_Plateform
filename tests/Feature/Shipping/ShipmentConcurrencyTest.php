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
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B8 — this milestone's exact "two staff members attempt to
 * fulfill the same quantity" scenario (Step 30). Simulated
 * sequentially (no real concurrent process available in this
 * environment — see B4/B5/B7's identical, honestly-labeled precedent).
 * Relies ENTIRELY on B4's InventoryService atomic-UPDATE guarantee,
 * now exercised via the new fulfillReservation() method — no new
 * concurrency mechanism was built for B8.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 * This test has NOT been run under genuine parallel load; that
 * verification is explicitly deferred to VS Code/CI with a real MySQL
 * instance, per this milestone's Step 30 instruction.
 */
final class ShipmentConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_staff_cannot_both_fulfill_the_full_ordered_quantity(): void
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create();
        $package->entitlements()->create(['key' => 'orders.basic', 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $product = Product::factory()->for($store)->create();
        $warehouse = Warehouse::query()->where('store_id', $store->id)->where('is_default', true)->firstOrFail();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['product_id' => $product->id]);
        app(TenantContext::class)->resolveToStore($store->id);
        app(InventoryService::class)->setOpeningStock($inventory, 5, 'Init', actorId: 1, idempotencyKey: 'open-'.$store->id);
        app(InventoryService::class)->reserve($inventory, 5, idempotencyKey: 'res-conc', referenceType: 'order', referenceId: 999);

        $order = Order::factory()->for($store)->create(['payment_status' => OrderPaymentStatus::Paid]);
        \App\Domain\Inventory\Models\StockReservation::query()->where('idempotency_key', 'res-conc')->update(['reference_id' => $order->id]);
        $orderItem = OrderItem::factory()->for($order)->create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 5]);

        $role = Role::factory()->for($store)->create(['slug' => 'owner']);
        $staffA = User::factory()->create();
        $staffB = User::factory()->create();
        $store->users()->attach($staffA, ['role_id' => $role->id, 'status' => 'active']);
        $store->users()->attach($staffB, ['role_id' => $role->id, 'status' => 'active']);

        $requestA = $this->actingAs($staffA)->postJson('/api/v1/shipments', [
            'order_id' => $order->public_id, 'warehouse_id' => $warehouse->id, 'carrier' => 'store_pickup',
            'items' => [['order_item_id' => $orderItem->id, 'quantity' => 5]],
            'idempotency_key' => 'ship-staff-a',
        ]);

        $requestB = $this->actingAs($staffB)->postJson('/api/v1/shipments', [
            'order_id' => $order->public_id, 'warehouse_id' => $warehouse->id, 'carrier' => 'store_pickup',
            'items' => [['order_item_id' => $orderItem->id, 'quantity' => 5]],
            'idempotency_key' => 'ship-staff-b',
        ]);

        $statuses = [$requestA->status(), $requestB->status()];
        sort($statuses);

        $this->assertSame([201, 422], $statuses, 'Exactly one staff member must succeed; the other must be rejected for exceeding the remaining quantity.');
        $this->assertSame(0, $inventory->fresh()->reserved);
        $this->assertSame(0, $inventory->fresh()->on_hand); // fully committed exactly once, not twice
        $this->assertDatabaseCount('shipments', 1);
    }
}
