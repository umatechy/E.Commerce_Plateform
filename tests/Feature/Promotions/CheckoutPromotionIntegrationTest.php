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
use App\Domain\Promotions\Models\Coupon;
use App\Domain\Promotions\Models\Promotion;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Shipping\Models\ShippingRate;
use App\Domain\Shipping\Models\ShippingZone;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B9 — end-to-end Checkout -> Promotion integration (Module 11 +
 * 14 combined). Confirms discount flows into Order.grand_total_minor
 * via OrderService's new additive parameter, order snapshot is
 * preserved, and idempotent replay does not double-consume usage.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class CheckoutPromotionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Store, 1: Product, 2: int} */
    private function setUpStore(): array
    {
        $store = Store::factory()->create();
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

        return [$store, $product, $method->id];
    }

    public function test_automatic_promotion_reduces_order_total(): void
    {
        [$store, $product, $methodId] = $this->setUpStore();
        Promotion::factory()->for($store)->create(['percentage_value' => 10]);

        $addResponse = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug]);
        $token = $addResponse->headers->get('X-Guest-Cart-Token');

        $response = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod', 'shipping_method_id' => $methodId, 'shipping_address' => ['country' => 'PK'],
            'guest_name' => 'Jane', 'guest_email' => 'jane@example.com', 'idempotency_key' => 'checkout-promo-1',
        ], ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token]);

        $response->assertCreated();
        $response->assertJsonPath('data.order.grand_total_minor', 9000); // 10000 - 10%
    }

    public function test_coupon_applied_at_cart_level_is_honored_at_checkout(): void
    {
        [$store, $product, $methodId] = $this->setUpStore();
        $promotion = Promotion::factory()->for($store)->create(['requires_coupon' => true, 'type' => 'fixed_amount', 'fixed_amount_minor' => 1500, 'currency' => 'USD']);
        Coupon::factory()->for($store)->for($promotion)->create(['code' => 'SAVE15', 'code_normalized' => 'SAVE15']);

        $addResponse = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug]);
        $token = $addResponse->headers->get('X-Guest-Cart-Token');
        $headers = ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token];

        $this->postJson('/api/v1/cart/coupon', ['code' => 'SAVE15'], $headers)->assertOk();

        $response = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod', 'shipping_method_id' => $methodId, 'shipping_address' => ['country' => 'PK'],
            'guest_name' => 'Jane', 'guest_email' => 'jane@example.com', 'idempotency_key' => 'checkout-coupon-1',
        ], $headers);

        $response->assertCreated();
        $response->assertJsonPath('data.order.grand_total_minor', 8500); // 10000 - 1500
        $this->assertDatabaseHas('order_promotions', ['coupon_code_snapshot' => 'SAVE15', 'discount_amount_minor' => 1500]);
        $this->assertSame(1, Coupon::query()->where('code_normalized', 'SAVE15')->value('used_count'));
    }

    public function test_invalid_coupon_is_rejected_when_applying_to_cart(): void
    {
        [$store, $product, $methodId] = $this->setUpStore();

        $response = $this->postJson('/api/v1/cart/coupon', ['code' => 'NOPE'], ['X-Store-Slug' => $store->slug]);

        $response->assertStatus(422)->assertJsonPath('code', 'coupon_not_eligible');
    }

    public function test_removing_a_coupon_recalculates_the_cart_immediately(): void
    {
        [$store, $product, $methodId] = $this->setUpStore();
        $promotion = Promotion::factory()->for($store)->create(['requires_coupon' => true, 'percentage_value' => 10]);
        Coupon::factory()->for($store)->for($promotion)->create(['code' => 'TEN', 'code_normalized' => 'TEN']);

        $addResponse = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug]);
        $token = $addResponse->headers->get('X-Guest-Cart-Token');
        $headers = ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token];
        $this->postJson('/api/v1/cart/coupon', ['code' => 'TEN'], $headers)->assertOk();

        $response = $this->deleteJson('/api/v1/cart/coupon', [], $headers);

        $response->assertOk();
        $response->assertJsonPath('data.promotion.applied', false);
    }

    public function test_client_supplied_discount_field_is_ignored(): void
    {
        [$store, $product, $methodId] = $this->setUpStore();

        $addResponse = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug]);
        $token = $addResponse->headers->get('X-Guest-Cart-Token');

        $response = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod', 'shipping_method_id' => $methodId, 'shipping_address' => ['country' => 'PK'],
            'discount_total_minor' => 999999, // not a real field — must be silently ignored
            'guest_name' => 'Jane', 'guest_email' => 'jane@example.com', 'idempotency_key' => 'checkout-tamper-1',
        ], ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token]);

        $response->assertCreated();
        $response->assertJsonPath('data.order.grand_total_minor', 10000); // untouched — no real promotion existed
    }

    public function test_duplicate_checkout_idempotency_key_does_not_double_consume_promotion_usage(): void
    {
        [$store, $product, $methodId] = $this->setUpStore();
        $promotion = Promotion::factory()->for($store)->create(['percentage_value' => 10, 'usage_limit' => 1]);

        $addResponse = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug]);
        $token = $addResponse->headers->get('X-Guest-Cart-Token');
        $headers = ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token];
        $payload = [
            'payment_method' => 'cod', 'shipping_method_id' => $methodId, 'shipping_address' => ['country' => 'PK'],
            'guest_name' => 'Jane', 'guest_email' => 'jane@example.com', 'idempotency_key' => 'checkout-replay-1',
        ];

        $first = $this->postJson('/api/v1/checkout', $payload, $headers);
        $second = $this->postJson('/api/v1/checkout', $payload, $headers);

        $first->assertCreated();
        $second->assertOk();
        $this->assertSame(1, $promotion->fresh()->used_count); // NOT 2
        $this->assertSame(1, \App\Domain\Promotions\Models\PromotionUsage::query()->count());
    }
}
