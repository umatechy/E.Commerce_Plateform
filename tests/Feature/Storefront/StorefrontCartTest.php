<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B24 — the cart as the storefront uses it: items addressed by
 * public id, lines carrying what a cart page shows, and the fix for
 * adding the same variant twice (previously a 500).
 */
final class StorefrontCartTest extends TestCase
{
    use InteractsWithStorefront, RefreshDatabase;

    public function test_adding_the_same_variant_twice_merges_into_one_line(): void
    {
        $store = $this->openStore();
        $product = $this->product($store, ['name' => 'Tee', 'slug' => 'tee', 'price_minor' => null]);
        $variant = $this->variant($product, ['size' => 'M'], 1500);
        $headers = $this->storefront($store);

        $first = $this->postJson('/api/v1/cart/items', ['variant' => $variant->public_id, 'quantity' => 1], $headers)->assertCreated();
        $headers['X-Guest-Cart-Token'] = $first->json('data.guest_token');
        $this->postJson('/api/v1/cart/items', ['variant' => $variant->public_id, 'quantity' => 2], $headers)->assertCreated();

        $this->getJson('/api/v1/cart', $headers)->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 3)
            ->assertJsonPath('data.items.0.product_name', 'Tee')
            ->assertJsonPath('data.items.0.product_slug', 'tee')
            ->assertJsonPath('data.items.0.variant_id', $variant->public_id)
            ->assertJsonPath('data.items.0.variant_options', ['size' => 'M'])
            ->assertJsonPath('data.subtotal_minor', 4500);
    }

    public function test_products_are_added_by_public_id_within_this_store_only(): void
    {
        $store = $this->openStore();
        $product = $this->product($store, ['price_minor' => 2000]);
        $foreign = $this->product($this->openStore());
        $searchOnly = $this->product($store, ['visibility' => 'search_only']);
        $headers = $this->storefront($store);

        $this->postJson('/api/v1/cart/items', ['product' => $product->public_id, 'quantity' => 1], $headers)->assertCreated();
        $this->postJson('/api/v1/cart/items', ['product' => $foreign->public_id, 'quantity' => 1], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('product');
        $this->postJson('/api/v1/cart/items', ['product' => $searchOnly->public_id, 'quantity' => 1], $headers)
            ->assertStatus(422);
        $this->postJson('/api/v1/cart/items', ['quantity' => 1], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('product_id');
    }
}
