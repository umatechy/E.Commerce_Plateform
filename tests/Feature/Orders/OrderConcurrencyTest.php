<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B5 — this milestone's exact "Stock = 1, Buyer A and Buyer B
 * submit orders concurrently" scenario. Simulated sequentially (no
 * real concurrent process available in this environment — see B4's
 * InventoryService docblock for the atomic-UPDATE strategy that makes
 * this safe under REAL concurrency; a genuine parallel-request test is
 * deferred to VS Code with a real MySQL instance).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class OrderConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_buyers_cannot_both_successfully_order_the_last_unit(): void
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create();
        $package->entitlements()->create(['key' => 'orders.basic', 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $role = $this->systemRole($store, 'owner');
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $role->id, 'status' => 'active']);
        $product = Product::factory()->for($store)->create(['price_minor' => 1000, 'currency' => 'USD']);
        $warehouse = Warehouse::query()->withoutTenantScope()->where('store_id', $store->id)->where('is_default', true)->firstOrFail();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['product_id' => $product->id]);
        $this->app->make(TenantContext::class)->resolveToStore($store->id);
        $this->app->make(InventoryService::class)->setOpeningStock($inventory, 1, 'Last unit', actorId: $owner->id, idempotencyKey: 'last-unit');

        $buyerAResponse = $this->actingAs($owner)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'guest_name' => 'Buyer A', 'guest_email' => 'a@example.com', 'idempotency_key' => 'buyer-a',
        ]);

        $buyerBResponse = $this->actingAs($owner)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'guest_name' => 'Buyer B', 'guest_email' => 'b@example.com', 'idempotency_key' => 'buyer-b',
        ]);

        $statuses = [$buyerAResponse->status(), $buyerBResponse->status()];
        sort($statuses);

        $this->assertSame([201, 422], $statuses, 'Exactly one buyer must succeed and the other must be rejected for insufficient stock.');
        $this->assertDatabaseCount('orders', 1);
    }
}
