<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

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
 * Phase B7 — end-to-end Checkout → Payment integration (Module 11 + 12
 * combined). Confirms B6's CheckoutService correctly calls the new
 * PaymentService without any change to OrderService itself.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class CheckoutPaymentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function setUpStore(): array
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create();
        foreach (['orders.basic', 'payment.cod', 'payment.online', 'shipping.basic'] as $feature) {
            $package->entitlements()->create(['key' => $feature, 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        }
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $product = Product::factory()->for($store)->create(['status' => 'active', 'visibility' => 'public', 'price_minor' => 2000, 'currency' => 'USD']);
        $warehouse = Warehouse::query()->where('store_id', $store->id)->where('is_default', true)->firstOrFail();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['product_id' => $product->id]);
        app(TenantContext::class)->resolveToStore($store->id);
        app(InventoryService::class)->setOpeningStock($inventory, 10, 'Init', actorId: 1, idempotencyKey: 'open-'.$store->id);
        $shippingMethodId = \App\Domain\Shipping\Models\ShippingMethod::query()->where('store_id', $store->id)->where('type', 'store_pickup')->value('id');

        return [$store, $product, $shippingMethodId];
    }

    public function test_checkout_with_cod_creates_a_pending_payment(): void
    {
        [$store, $product, $shippingMethodId] = $this->setUpStore();

        $addResponse = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug]);
        $token = $addResponse->headers->get('X-Guest-Cart-Token');

        $response = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod', 'shipping_method_id' => $shippingMethodId, 'shipping_address' => ['country' => 'PK'],
            'guest_name' => 'Jane', 'guest_email' => 'jane@example.com',
            'idempotency_key' => 'checkout-cod-1',
        ], ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token]);

        $response->assertCreated();
        $response->assertJsonPath('data.payment.method', 'cod');
        $response->assertJsonPath('data.payment.status', 'pending');
        $response->assertJsonPath('data.redirect_url', null);
    }

    public function test_checkout_with_mock_redirect_returns_a_redirect_url(): void
    {
        [$store, $product, $shippingMethodId] = $this->setUpStore();

        $addResponse = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug]);
        $token = $addResponse->headers->get('X-Guest-Cart-Token');

        $response = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'mock_redirect', 'shipping_method_id' => $shippingMethodId, 'shipping_address' => ['country' => 'PK'],
            'guest_name' => 'Jane', 'guest_email' => 'jane@example.com',
            'idempotency_key' => 'checkout-mock-1',
        ], ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token]);

        $response->assertCreated();
        $this->assertNotNull($response->json('data.redirect_url'));
    }

    public function test_full_cod_lifecycle_order_confirmed_payment_pending_then_staff_collects_cash(): void
    {
        [$store, $product, $shippingMethodId] = $this->setUpStore();
        $addResponse = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug]);
        $token = $addResponse->headers->get('X-Guest-Cart-Token');

        $checkoutResponse = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod', 'shipping_method_id' => $shippingMethodId, 'shipping_address' => ['country' => 'PK'],
            'guest_name' => 'Jane', 'guest_email' => 'jane@example.com',
            'idempotency_key' => 'checkout-cod-lifecycle',
        ], ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token]);

        $paymentPublicId = $checkoutResponse->json('data.payment.id');
        $payment = \App\Domain\Payments\Models\Payment::query()->where('public_id', $paymentPublicId)->firstOrFail();

        $role = Role::factory()->for($store)->create(['slug' => 'owner']);
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $role->id, 'status' => 'active']);

        $confirmResponse = $this->actingAs($owner)->postJson("/api/v1/payments/{$payment->id}/manual-confirm", [
            'amount_minor' => 2000, 'reference' => 'CASH-COLLECTED-1',
        ]);

        $confirmResponse->assertCreated();
        $this->assertSame('paid', $payment->fresh()->status->value);
        $this->assertSame('paid', $payment->fresh()->order->payment_status->value);
    }
}
