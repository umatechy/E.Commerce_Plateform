<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Catalog\Models\Product;
use App\Domain\Marketing\Services\AbandonedCartDetectionService;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B10 — Abandoned cart detection (Module 15 §33-35, this
 * milestone's Step 9: "do not confuse cart expiration with marketing
 * abandonment").
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class AbandonedCartDetectionTest extends TestCase
{
    use RefreshDatabase;

    private function cartWithItem(Store $store, ?Customer $customer, \Illuminate\Support\Carbon $updatedAt): Cart
    {
        $product = Product::factory()->for($store)->create();
        $cart = Cart::factory()->for($store)->create(['customer_id' => $customer?->id, 'guest_token' => $customer === null ? bin2hex(random_bytes(16)) : null]);
        CartItem::query()->create(['store_id' => $store->id, 'cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1, 'price_at_add_minor' => 1000]);
        // updated_at is not mass-assignable (not in Cart::$fillable),
        // AND Eloquent's save() would normally overwrite it back to
        // "now" automatically — both forceFill() (for the assignment
        // restriction) and disabling $timestamps (to stop the
        // auto-touch) are needed to backdate it for this test's "how
        // long has this cart been idle" setup.
        $cart->timestamps = false;
        $cart->forceFill(['updated_at' => $updatedAt])->save();

        return $cart;
    }

    public function test_an_old_cart_with_a_customer_is_detected(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $customer = Customer::factory()->for($store)->create();
        $cart = $this->cartWithItem($store, $customer, now()->subHours(3));

        $count = app(AbandonedCartDetectionService::class)->detectAndNotify();

        $this->assertSame(1, $count);
        $this->assertNotNull($cart->fresh()->abandoned_marketing_notified_at);
    }

    public function test_a_recently_updated_cart_is_not_yet_detected(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $customer = Customer::factory()->for($store)->create();
        $this->cartWithItem($store, $customer, now()->subMinutes(10));

        $count = app(AbandonedCartDetectionService::class)->detectAndNotify();

        $this->assertSame(0, $count);
    }

    public function test_a_guest_cart_with_no_customer_is_never_detected(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $this->cartWithItem($store, null, now()->subHours(3));

        $count = app(AbandonedCartDetectionService::class)->detectAndNotify();

        $this->assertSame(0, $count);
    }

    public function test_a_cart_already_notified_is_never_detected_twice(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $customer = Customer::factory()->for($store)->create();
        $cart = $this->cartWithItem($store, $customer, now()->subHours(3));

        app(AbandonedCartDetectionService::class)->detectAndNotify();
        $secondRunCount = app(AbandonedCartDetectionService::class)->detectAndNotify();

        $this->assertSame(0, $secondRunCount);
    }

    public function test_an_empty_cart_is_never_detected(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $customer = Customer::factory()->for($store)->create();
        $cart = Cart::factory()->for($store)->create(['customer_id' => $customer->id]);
        $cart->timestamps = false;
        $cart->forceFill(['updated_at' => now()->subHours(3)])->save();

        $count = app(AbandonedCartDetectionService::class)->detectAndNotify();

        $this->assertSame(0, $count);
    }
}
