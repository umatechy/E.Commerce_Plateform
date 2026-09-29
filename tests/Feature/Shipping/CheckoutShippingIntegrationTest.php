<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Shipping\Models\ShippingRate;
use App\Domain\Shipping\Models\ShippingZone;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B8 — end-to-end Checkout -> shipping cost integration (Module
 * 11 + 13 combined). Confirms shipping cost flows into
 * Order.grand_total_minor via OrderService's new additive parameter,
 * with zero change to OrderService's core order-creation logic.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class CheckoutShippingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_includes_server_calculated_shipping_cost_in_grand_total(): void
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create();
        foreach (['orders.basic', 'payment.cod', 'shipping.basic'] as $feature) {
            $package->entitlements()->create(['key' => $feature, 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        }
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $product = Product::factory()->for($store)->create(['status' => 'active', 'visibility' => 'public', 'price_minor' => 2000, 'currency' => 'USD']);
        $warehouse = Warehouse::query()->withoutTenantScope()->where('store_id', $store->id)->where('is_default', true)->firstOrFail();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['product_id' => $product->id]);
        app(TenantContext::class)->resolveToStore($store->id);
        app(InventoryService::class)->setOpeningStock($inventory, 10, 'Init', actorId: $this->actorId(), idempotencyKey: 'open-'.$store->id);

        $zone = ShippingZone::factory()->for($store)->create(['country' => 'PK']);
        $method = ShippingMethod::factory()->for($store)->create();
        ShippingRate::factory()->for($zone, 'zone')->for($method, 'method')->create(['base_cost_minor' => 500]);

        $addResponse = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug]);
        $token = $addResponse->headers->get('X-Guest-Cart-Token');

        $response = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod', 'shipping_method_id' => $method->id, 'shipping_address' => ['country' => 'PK'],
            'guest_name' => 'Jane', 'guest_email' => 'jane@example.com', 'idempotency_key' => 'checkout-ship-1',
        ], ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token]);

        $response->assertCreated();
        $response->assertJsonPath('data.order.grand_total_minor', 2500); // 2000 subtotal + 500 shipping
    }

    public function test_digital_only_cart_skips_shipping_entirely(): void
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create();
        foreach (['orders.basic', 'payment.cod', 'shipping.basic'] as $feature) {
            $package->entitlements()->create(['key' => $feature, 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        }
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $digitalProduct = Product::factory()->for($store)->create([
            'status' => 'active', 'visibility' => 'public', 'price_minor' => 1500, 'currency' => 'USD', 'type' => 'digital',
        ]);

        $addResponse = $this->postJson('/api/v1/cart/items', ['product_id' => $digitalProduct->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug]);
        $token = $addResponse->headers->get('X-Guest-Cart-Token');

        // No shipping_method_id / shipping_address supplied at all.
        $response = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod',
            'guest_name' => 'Jane', 'guest_email' => 'jane@example.com', 'idempotency_key' => 'checkout-digital-1',
        ], ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token]);

        $response->assertCreated();
        $response->assertJsonPath('data.order.grand_total_minor', 1500); // no shipping added
    }

    public function test_checkout_fails_when_destination_is_not_serviceable(): void
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create();
        foreach (['orders.basic', 'payment.cod', 'shipping.basic'] as $feature) {
            $package->entitlements()->create(['key' => $feature, 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        }
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $product = Product::factory()->for($store)->create(['status' => 'active', 'visibility' => 'public', 'price_minor' => 2000, 'currency' => 'USD']);
        $warehouse = Warehouse::query()->withoutTenantScope()->where('store_id', $store->id)->where('is_default', true)->firstOrFail();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['product_id' => $product->id]);
        app(TenantContext::class)->resolveToStore($store->id);
        app(InventoryService::class)->setOpeningStock($inventory, 10, 'Init', actorId: $this->actorId(), idempotencyKey: 'open-'.$store->id);
        $method = ShippingMethod::factory()->for($store)->create(); // no zone/rate configured for it

        $addResponse = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug]);
        $token = $addResponse->headers->get('X-Guest-Cart-Token');

        $response = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod', 'shipping_method_id' => $method->id, 'shipping_address' => ['country' => 'PK'],
            'guest_name' => 'Jane', 'guest_email' => 'jane@example.com', 'idempotency_key' => 'checkout-unserviceable',
        ], ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token]);

        $response->assertStatus(422)->assertJsonPath('code', 'destination_not_serviceable');
    }

    public function test_checkout_requires_shipping_method_for_physical_cart(): void
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create();
        foreach (['orders.basic', 'payment.cod', 'shipping.basic'] as $feature) {
            $package->entitlements()->create(['key' => $feature, 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        }
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $product = Product::factory()->for($store)->create(['status' => 'active', 'visibility' => 'public', 'price_minor' => 2000, 'currency' => 'USD']);
        $warehouse = Warehouse::query()->withoutTenantScope()->where('store_id', $store->id)->where('is_default', true)->firstOrFail();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['product_id' => $product->id]);
        app(TenantContext::class)->resolveToStore($store->id);
        app(InventoryService::class)->setOpeningStock($inventory, 10, 'Init', actorId: $this->actorId(), idempotencyKey: 'open-'.$store->id);

        $addResponse = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug]);
        $token = $addResponse->headers->get('X-Guest-Cart-Token');

        $response = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod',
            'guest_name' => 'Jane', 'guest_email' => 'jane@example.com', 'idempotency_key' => 'checkout-no-method',
        ], ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token]);

        $response->assertStatus(422)->assertJsonValidationErrors('shipping_method_id');
    }
}
