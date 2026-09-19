<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Domain\Catalog\Models\Product;
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
 * Phase B6 — this milestone's exact "Stock = 1, two checkout requests"
 * scenario (Step 23). Simulated sequentially (no real concurrent
 * process available in this environment — see B4/B5's identical,
 * honestly-labeled precedent). Relies ENTIRELY on B4's
 * InventoryService atomic-UPDATE guarantee via OrderService (Phase B5)
 * — no new concurrency mechanism was built for B6.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 * This test has NOT been run under genuine parallel load; that
 * verification is explicitly deferred to VS Code/CI with a real MySQL
 * instance, per this milestone's Step 23 instruction.
 */
final class CheckoutConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_guest_checkouts_cannot_both_buy_the_last_unit(): void
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create();
        $package->entitlements()->create(['key' => 'orders.basic', 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $product = Product::factory()->for($store)->create(['status' => 'active', 'visibility' => 'public', 'price_minor' => 1000, 'currency' => 'USD']);
        $warehouse = Warehouse::query()->where('store_id', $store->id)->where('is_default', true)->firstOrFail();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['product_id' => $product->id]);
        app(TenantContext::class)->resolveToStore($store->id);
        app(InventoryService::class)->setOpeningStock($inventory, 1, 'Last unit', actorId: 1, idempotencyKey: 'last-unit-b6');

        $cartA = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug]);
        $tokenA = $cartA->headers->get('X-Guest-Cart-Token');

        $cartB = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug]);
        $tokenB = $cartB->headers->get('X-Guest-Cart-Token');

        $this->assertNotSame($tokenA, $tokenB, 'Sanity check: two independent guest carts.');

        $checkoutA = $this->postJson('/api/v1/checkout', [
            'guest_name' => 'A', 'guest_email' => 'a@example.com', 'idempotency_key' => 'checkout-a',
        ], ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $tokenA]);

        $checkoutB = $this->postJson('/api/v1/checkout', [
            'guest_name' => 'B', 'guest_email' => 'b@example.com', 'idempotency_key' => 'checkout-b',
        ], ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $tokenB]);

        $statuses = [$checkoutA->status(), $checkoutB->status()];
        sort($statuses);

        $this->assertSame([201, 422], $statuses, 'Exactly one guest must succeed; the other must be rejected for insufficient stock.');
        $this->assertDatabaseCount('orders', 1);
    }
}
