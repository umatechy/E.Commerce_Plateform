<?php

declare(strict_types=1);

namespace Tests\Feature\Promotions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Promotions\Models\Promotion;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Shipping\Models\ShippingRate;
use App\Domain\Shipping\Models\ShippingZone;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B9 — this milestone's exact "usage limit = 1, two concurrent
 * redemption requests" scenario (Step 30). Simulated sequentially (no
 * real concurrent process available in this environment — see
 * B4/B5/B7/B8's identical, honestly-labeled precedent). Relies
 * ENTIRELY on the same atomic-conditional-UPDATE strategy as every
 * other usage-limit mechanism in this codebase — no new concurrency
 * mechanism was built for B9.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 * This test has NOT been run under genuine parallel load; that
 * verification is explicitly deferred to VS Code/CI with a real MySQL
 * instance, per this milestone's Step 30 instruction.
 */
final class PromotionUsageConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_concurrent_checkouts_cannot_both_consume_the_final_usage_slot(): void
    {
        $store = Store::factory()->create();
        $this->storeCurrency($store, 'USD'); // its products and rates are priced in USD
        $package = Package::factory()->create();
        foreach (['orders.basic', 'payment.cod', 'shipping.basic'] as $feature) {
            $package->entitlements()->create(['key' => $feature, 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        }
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $product = Product::factory()->for($store)->create(['status' => 'active', 'visibility' => 'public', 'price_minor' => 10000, 'currency' => 'USD']);
        $warehouse = Warehouse::query()->withoutTenantScope()->where('store_id', $store->id)->where('is_default', true)->firstOrFail();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['product_id' => $product->id]);
        app(TenantContext::class)->resolveToStore($store->id);
        app(InventoryService::class)->setOpeningStock($inventory, 10, 'Init', actorId: $this->actorId(), idempotencyKey: 'open-'.$store->id);
        $zone = ShippingZone::factory()->for($store)->create(['country' => 'PK']);
        $method = ShippingMethod::factory()->for($store)->create();
        ShippingRate::factory()->for($zone, 'zone')->for($method, 'method')->create(['base_cost_minor' => 0]);
        Promotion::factory()->for($store)->create(['percentage_value' => 10, 'usage_limit' => 1]);

        $cartA = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug]);
        $tokenA = $cartA->headers->get('X-Guest-Cart-Token');
        $cartB = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug]);
        $tokenB = $cartB->headers->get('X-Guest-Cart-Token');

        $payloadFor = fn (string $email, string $key) => [
            'payment_method' => 'cod', 'shipping_method_id' => $method->id, 'shipping_address' => ['country' => 'PK'],
            'guest_name' => 'Buyer', 'guest_email' => $email, 'idempotency_key' => $key,
        ];

        $checkoutA = $this->postJson('/api/v1/checkout', $payloadFor('a@example.com', 'checkout-conc-a'), [
            'X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $tokenA,
        ]);
        $checkoutB = $this->postJson('/api/v1/checkout', $payloadFor('b@example.com', 'checkout-conc-b'), [
            'X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $tokenB,
        ]);

        // Both checkouts succeed (the promotion usage limit does not
        // block ORDER creation — Non-Negotiable Rule #9/#10 concerns
        // the discount amount, not order success); what must never
        // happen is BOTH orders receiving the discount.
        $checkoutA->assertCreated();
        $checkoutB->assertCreated();

        $totals = [$checkoutA->json('data.order.grand_total_minor'), $checkoutB->json('data.order.grand_total_minor')];
        sort($totals);

        $this->assertSame([9000, 10000], $totals, 'Exactly one order must receive the discount; the other must pay full price.');
        $this->assertSame(1, \App\Domain\Promotions\Models\PromotionUsage::query()->count());
    }
}
