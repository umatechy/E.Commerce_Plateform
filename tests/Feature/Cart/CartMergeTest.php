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
 * Phase B6 — Cart Merge on login (Module 11 §22).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class CartMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cart_merges_into_customer_cart_on_login(): void
    {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['email' => 'jane@example.com', 'password' => Hash::make('correct-horse-battery-staple')]);
        $product = Product::factory()->for($store)->create(['status' => 'active', 'visibility' => 'public']);

        $addResponse = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 3], ['X-Store-Slug' => $store->slug]);
        $guestToken = $addResponse->headers->get('X-Guest-Cart-Token');

        $loginResponse = $this->postJson('/api/v1/customer/login', [
            'email' => 'jane@example.com', 'password' => 'correct-horse-battery-staple',
        ], ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $guestToken]);

        $loginResponse->assertOk();
        $token = $loginResponse->json('token');

        $cartResponse = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/cart', ['X-Store-Slug' => $store->slug]);

        $cartResponse->assertOk();
        $this->assertCount(1, $cartResponse->json('data.items'));
        $this->assertSame(3, $cartResponse->json('data.items')[0]['quantity']);
    }

    public function test_merged_guest_cart_is_marked_merged_not_deleted(): void
    {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['email' => 'jane@example.com', 'password' => Hash::make('correct-horse-battery-staple')]);
        $product = Product::factory()->for($store)->create(['status' => 'active', 'visibility' => 'public']);

        $addResponse = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug]);
        $guestToken = $addResponse->headers->get('X-Guest-Cart-Token');
        $guestCartId = $addResponse->json('data.id');

        $this->postJson('/api/v1/customer/login', [
            'email' => 'jane@example.com', 'password' => 'correct-horse-battery-staple',
        ], ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $guestToken]);

        $guestCart = \App\Domain\Cart\Models\Cart::query()->withoutTenantScope()->where('public_id', $guestCartId)->firstOrFail();
        $this->assertSame('merged', $guestCart->status->value);
    }

    public function test_matching_products_sum_quantities_on_merge(): void
    {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['email' => 'jane@example.com', 'password' => Hash::make('correct-horse-battery-staple')]);
        $product = Product::factory()->for($store)->create(['status' => 'active', 'visibility' => 'public']);

        // Customer already has 2 of this product in their own cart (pre-login).
        $token = $customer->createToken('t')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 2], ['X-Store-Slug' => $store->slug]);

        // Guest (pre-login, different browser) added 3 more of the same product.
        $guestAdd = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 3], ['X-Store-Slug' => $store->slug]);
        $guestToken = $guestAdd->headers->get('X-Guest-Cart-Token');

        $this->postJson('/api/v1/customer/login', [
            'email' => 'jane@example.com', 'password' => 'correct-horse-battery-staple',
        ], ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $guestToken]);

        $cartResponse = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/cart', ['X-Store-Slug' => $store->slug]);

        $this->assertCount(1, $cartResponse->json('data.items'));
        $this->assertSame(5, $cartResponse->json('data.items')[0]['quantity']); // 2 + 3
    }
}
