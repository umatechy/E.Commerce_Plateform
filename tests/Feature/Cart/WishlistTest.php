<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Domain\Catalog\Models\Product;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase B6 — Wishlist (Module 11 §64-71): authenticated-customer-only,
 * duplicate-safe, ownership-enforced.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class WishlistTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(Customer $customer): string
    {
        return $customer->createToken('t')->plainTextToken;
    }

    public function test_authenticated_customer_can_add_a_wishlist_item(): void
    {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        $product = Product::factory()->for($store)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($customer))
            ->postJson('/api/v1/wishlist', ['product_id' => $product->id]);

        $response->assertCreated();
        $this->assertDatabaseHas('wishlist_items', ['customer_id' => $customer->id, 'product_id' => $product->id]);
    }

    public function test_wishlist_requires_authentication(): void
    {
        $this->postJson('/api/v1/wishlist', ['product_id' => 1])->assertStatus(401);
    }

    public function test_duplicate_wishlist_item_is_not_created_twice(): void
    {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        $product = Product::factory()->for($store)->create();
        $header = ['Authorization' => 'Bearer '.$this->tokenFor($customer)];

        $this->withHeaders($header)->postJson('/api/v1/wishlist', ['product_id' => $product->id])->assertCreated();
        $this->withHeaders($header)->postJson('/api/v1/wishlist', ['product_id' => $product->id])->assertCreated();

        $this->assertSame(1, \App\Domain\Cart\Models\WishlistItem::query()->where('customer_id', $customer->id)->count());
    }

    public function test_customer_cannot_delete_another_customers_wishlist_item(): void
    {
        $store = Store::factory()->create();
        $ownerCustomer = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        $otherCustomer = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        $product = Product::factory()->for($store)->create();
        $item = \App\Domain\Cart\Models\WishlistItem::query()->create([
            'store_id' => $store->id, 'customer_id' => $ownerCustomer->id, 'product_id' => $product->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($otherCustomer))
            ->deleteJson("/api/v1/wishlist/{$item->id}");

        $response->assertStatus(404);
    }

    public function test_moving_a_wishlist_item_to_cart_removes_it_from_wishlist(): void
    {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        $product = Product::factory()->for($store)->create(['status' => 'active', 'visibility' => 'public', 'price_minor' => 500]);
        $item = \App\Domain\Cart\Models\WishlistItem::query()->create([
            'store_id' => $store->id, 'customer_id' => $customer->id, 'product_id' => $product->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($customer))
            ->postJson("/api/v1/wishlist/{$item->id}/move-to-cart");

        $response->assertOk();
        $this->assertCount(1, $response->json('data.items'));
        $this->assertDatabaseMissing('wishlist_items', ['id' => $item->id]);
    }

    public function test_wishlist_item_reports_unavailable_when_product_becomes_hidden(): void
    {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        $product = Product::factory()->for($store)->create(['status' => 'active', 'visibility' => 'public']);
        \App\Domain\Cart\Models\WishlistItem::query()->create([
            'store_id' => $store->id, 'customer_id' => $customer->id, 'product_id' => $product->id,
        ]);
        $product->update(['status' => 'archived']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($customer))->getJson('/api/v1/wishlist');

        $response->assertOk();
        $response->assertJsonPath('data.0.availability', 'unavailable');
    }
}
