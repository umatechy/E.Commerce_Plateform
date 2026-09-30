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
 * Phase B6 — Checkout (Module 11 §12/§42-58): one-shot, calls
 * OrderService directly, server-authoritative, idempotent.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function setUpEntitledStore(int $stock = 100): array
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create();
        $package->entitlements()->create(['key' => 'orders.basic', 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        $package->entitlements()->create(['key' => 'payment.cod', 'type' => EntitlementType::Feature, 'boolean_value' => true]); // Phase B7: checkout now requires a payment-method entitlement too
        $package->entitlements()->create(['key' => 'shipping.basic', 'type' => EntitlementType::Feature, 'boolean_value' => true]); // Phase B8: checkout now requires a shipping entitlement too
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);

        $product = Product::factory()->for($store)->create(['status' => 'active', 'visibility' => 'public', 'price_minor' => 2000, 'currency' => 'USD']);
        $warehouse = Warehouse::query()->withoutTenantScope()->where('store_id', $store->id)->where('is_default', true)->firstOrFail();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['product_id' => $product->id]);

        app(TenantContext::class)->resolveToStore($store->id);
        app(InventoryService::class)->setOpeningStock($inventory, $stock, 'Init', actorId: $this->actorId(), idempotencyKey: 'open-'.$store->id);

        // Phase B8: StoreObserver auto-creates a default catch-all
        // zone + Store Pickup method/rate for every new store — reuse
        // it rather than seeding a second shipping configuration.
        $shippingMethodId = \App\Domain\Shipping\Models\ShippingMethod::query()->where('store_id', $store->id)->where('type', 'store_pickup')->value('id');

        return [$store, $product, $shippingMethodId];
    }

    public function test_guest_checkout_creates_a_confirmed_order(): void
    {
        [$store, $product, $shippingMethodId] = $this->setUpEntitledStore();

        $addResponse = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 2], ['X-Store-Slug' => $store->slug]);
        $token = $addResponse->headers->get('X-Guest-Cart-Token');

        $response = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod',
            'shipping_method_id' => $shippingMethodId, 'shipping_address' => ['country' => 'PK'],
            'guest_name' => 'Jane Doe', 'guest_email' => 'jane@example.com', 'idempotency_key' => 'checkout-1',
        ], ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token]);

        $response->assertCreated();
        $response->assertJsonPath('data.order.status', 'confirmed');
        $response->assertJsonPath('data.order.grand_total_minor', 4000);
    }

    public function test_checkout_marks_the_cart_as_converted(): void
    {
        [$store, $product, $shippingMethodId] = $this->setUpEntitledStore();

        $addResponse = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug]);
        $token = $addResponse->headers->get('X-Guest-Cart-Token');
        $cartId = $addResponse->json('data.id');

        $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod',
            'shipping_method_id' => $shippingMethodId, 'shipping_address' => ['country' => 'PK'],
            'guest_name' => 'Jane', 'guest_email' => 'jane@example.com', 'idempotency_key' => 'checkout-2',
        ], ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token])->assertCreated();

        $cart = \App\Domain\Cart\Models\Cart::query()->where('public_id', $cartId)->firstOrFail();
        $this->assertSame('converted', $cart->status->value);
        $this->assertNotNull($cart->converted_order_id);
    }

    public function test_checkout_fails_on_empty_cart(): void
    {
        [$store, $product, $shippingMethodId] = $this->setUpEntitledStore();

        $response = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod',
            'shipping_method_id' => $shippingMethodId, 'shipping_address' => ['country' => 'PK'],
            'guest_name' => 'Jane', 'guest_email' => 'jane@example.com', 'idempotency_key' => 'checkout-empty',
        ], ['X-Store-Slug' => $store->slug]);

        $response->assertStatus(422)->assertJsonPath('code', 'checkout_not_allowed');
    }

    public function test_checkout_fails_when_cart_has_a_price_change_issue(): void
    {
        [$store, $product, $shippingMethodId] = $this->setUpEntitledStore();

        $addResponse = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug]);
        $token = $addResponse->headers->get('X-Guest-Cart-Token');
        $product->update(['price_minor' => 9999]);

        $response = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod',
            'shipping_method_id' => $shippingMethodId, 'shipping_address' => ['country' => 'PK'],
            'guest_name' => 'Jane', 'guest_email' => 'jane@example.com', 'idempotency_key' => 'checkout-price',
        ], ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token]);

        $response->assertStatus(422)->assertJsonPath('code', 'checkout_not_allowed');
    }

    public function test_checkout_fails_with_insufficient_stock(): void
    {
        [$store, $product, $shippingMethodId] = $this->setUpEntitledStore(stock: 1);

        $addResponse = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug]);
        $token = $addResponse->headers->get('X-Guest-Cart-Token');

        // Reduce available stock to 0 AFTER the item was added (soft
        // cart-level checks don't reserve — see CartService docblock).
        $inventory = Inventory::query()->where('store_id', $store->id)->where('product_id', $product->id)->firstOrFail();
        app(TenantContext::class)->resolveToStore($store->id);
        app(InventoryService::class)->adjustStock($inventory, -1, 'sold elsewhere', actorId: $this->actorId(), idempotencyKey: 'reduce-1');

        $response = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod',
            'shipping_method_id' => $shippingMethodId, 'shipping_address' => ['country' => 'PK'],
            'guest_name' => 'Jane', 'guest_email' => 'jane@example.com', 'idempotency_key' => 'checkout-oos',
        ], ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token]);

        $response->assertStatus(422)->assertJsonPath('code', 'checkout_not_allowed');
    }

    public function test_duplicate_checkout_idempotency_key_does_not_create_a_second_order(): void
    {
        [$store, $product, $shippingMethodId] = $this->setUpEntitledStore();

        $addResponse = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug]);
        $token = $addResponse->headers->get('X-Guest-Cart-Token');
        $headers = ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token];
        $payload = ['payment_method' => 'cod', 'shipping_method_id' => $shippingMethodId, 'shipping_address' => ['country' => 'PK'], 'guest_name' => 'Jane', 'guest_email' => 'jane@example.com', 'idempotency_key' => 'same-checkout-key'];

        $first = $this->postJson('/api/v1/checkout', $payload, $headers);
        $second = $this->postJson('/api/v1/checkout', $payload, $headers);

        $first->assertCreated();
        $second->assertOk(); // 200, not 201 — replay
        $this->assertSame($first->json('data.order.id'), $second->json('data.order.id'));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_checkout_without_guest_contact_info_and_no_authentication_is_rejected(): void
    {
        [$store, $product, $shippingMethodId] = $this->setUpEntitledStore();

        $addResponse = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug]);
        $token = $addResponse->headers->get('X-Guest-Cart-Token');

        $response = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod',
            'shipping_method_id' => $shippingMethodId, 'shipping_address' => ['country' => 'PK'],
            'idempotency_key' => 'checkout-no-contact',
        ], ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token]);

        $response->assertStatus(422);
    }
}
