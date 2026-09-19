<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Domain\Catalog\Models\Product;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B6 — Guest cart CRUD, provisional pricing, price-change
 * detection (Module 11 §6/§10/§13-14).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class CartTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_request_receives_a_new_guest_cart_and_token(): void
    {
        $store = Store::factory()->create();

        $response = $this->getJson('/api/v1/cart', ['X-Store-Slug' => $store->slug]);

        $response->assertOk();
        $this->assertNotEmpty($response->headers->get('X-Guest-Cart-Token'));
        $response->assertJsonPath('data.items', []);
    }

    public function test_adding_an_item_computes_live_price(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['status' => 'active', 'visibility' => 'public', 'price_minor' => 1500]);

        $response = $this->postJson('/api/v1/cart/items', [
            'product_id' => $product->id, 'quantity' => 2,
        ], ['X-Store-Slug' => $store->slug]);

        $response->assertCreated();
        $response->assertJsonPath('data.subtotal_minor', 3000);
    }

    public function test_reusing_the_guest_token_returns_the_same_cart(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['status' => 'active', 'visibility' => 'public', 'price_minor' => 1000]);

        $first = $this->postJson('/api/v1/cart/items', [
            'product_id' => $product->id, 'quantity' => 1,
        ], ['X-Store-Slug' => $store->slug]);

        $token = $first->headers->get('X-Guest-Cart-Token');

        $second = $this->getJson('/api/v1/cart', ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token]);

        $second->assertOk();
        $second->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertCount(1, $second->json('data.items'));
    }

    public function test_adding_the_same_product_twice_merges_quantity(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['status' => 'active', 'visibility' => 'public', 'price_minor' => 1000]);

        $first = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 2], ['X-Store-Slug' => $store->slug]);
        $token = $first->headers->get('X-Guest-Cart-Token');

        $second = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 3], [
            'X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token,
        ]);

        $second->assertCreated();
        $this->assertCount(1, $second->json('data.items'));
        $this->assertSame(5, $second->json('data.items')[0]['quantity']);
    }

    public function test_hidden_product_cannot_be_added_to_cart(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['status' => 'draft', 'visibility' => 'hidden']);

        $response = $this->postJson('/api/v1/cart/items', [
            'product_id' => $product->id, 'quantity' => 1,
        ], ['X-Store-Slug' => $store->slug]);

        $response->assertStatus(422);
    }

    public function test_zero_quantity_is_rejected(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['status' => 'active', 'visibility' => 'public']);

        $response = $this->postJson('/api/v1/cart/items', [
            'product_id' => $product->id, 'quantity' => 0,
        ], ['X-Store-Slug' => $store->slug]);

        $response->assertStatus(422);
    }

    public function test_price_change_after_add_is_detected_on_retrieval(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['status' => 'active', 'visibility' => 'public', 'price_minor' => 1000]);

        $first = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug]);
        $token = $first->headers->get('X-Guest-Cart-Token');

        $product->update(['price_minor' => 2000]);

        $response = $this->getJson('/api/v1/cart', ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token]);

        $response->assertOk();
        $response->assertJsonPath('data.has_issues', true);
        $this->assertSame('price_changed', $response->json('data.items')[0]['issue']);
    }

    public function test_removing_an_item_updates_the_cart(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['status' => 'active', 'visibility' => 'public']);

        $addResponse = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug]);
        $token = $addResponse->headers->get('X-Guest-Cart-Token');
        $itemId = $addResponse->json('data.items')[0]['cart_item_id'];

        $response = $this->deleteJson("/api/v1/cart/items/{$itemId}", [], ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token]);

        $response->assertOk();
        $this->assertCount(0, $response->json('data.items'));
    }

    public function test_a_stores_cart_never_leaks_into_another_stores_cart_lookup(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $product = Product::factory()->for($storeA)->create(['status' => 'active', 'visibility' => 'public']);

        $addResponse = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1], ['X-Store-Slug' => $storeA->slug]);
        $token = $addResponse->headers->get('X-Guest-Cart-Token');

        // Same guest token presented against a DIFFERENT store's tenant
        // context must never resolve Store A's cart (BelongsToTenant's
        // global scope makes this structurally impossible, not merely
        // policy-denied).
        $response = $this->getJson('/api/v1/cart', ['X-Store-Slug' => $storeB->slug, 'X-Guest-Cart-Token' => $token]);

        $response->assertOk();
        $this->assertNotSame($addResponse->json('data.id'), $response->json('data.id'));
        $this->assertCount(0, $response->json('data.items'));
    }
}
