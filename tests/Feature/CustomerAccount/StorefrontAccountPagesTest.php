<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerAccount;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase B25 — account pages and the storefront wishlist. */
final class StorefrontAccountPagesTest extends TestCase
{
    use InteractsWithCustomerAccounts, RefreshDatabase;

    public function test_account_pages_render_and_are_never_indexed(): void
    {
        $store = $this->openStore();
        $base = "/shop/{$store->slug}/account";

        foreach (['' => 'Dashboard', '/login' => 'Login', '/register' => 'Register', '/forgot-password' => 'ForgotPassword',
            '/orders' => 'Orders', '/orders/01JORDER000000000000000000' => 'Order', '/addresses' => 'Addresses',
            '/profile' => 'Profile', '/wishlist' => 'Wishlist'] as $path => $page) {
            $this->withoutVite()->get($base.$path)->assertOk()
                ->assertInertia(fn ($p) => $p->component("Storefront/Account/{$page}")->where('seo.robots', 'noindex, nofollow'));
        }

        $this->withoutVite()->get("{$base}/orders/01JORDER000000000000000000")->assertInertia(fn ($p) => $p->where('order_id', '01JORDER000000000000000000'));
        $this->withoutVite()->get("{$base}/reset-password?token=abc&email=a%40b.c")->assertOk()
            ->assertInertia(fn ($p) => $p->where('token', 'abc')->where('email', 'a@b.c'));
    }

    public function test_the_post_login_redirect_stays_inside_this_storefront(): void
    {
        $store = $this->openStore();
        $base = "/shop/{$store->slug}";

        $this->withoutVite()->get("{$base}/account/login?redirect=".urlencode("{$base}/account/orders"))
            ->assertInertia(fn ($p) => $p->where('redirect', "{$base}/account/orders"));
        foreach (['https://evil.example/', '//evil.example', '/shop/other-store/account', 'javascript:alert(1)'] as $target) {
            $this->withoutVite()->get("{$base}/account/login?redirect=".urlencode($target))
                ->assertInertia(fn ($p) => $p->where('redirect', null));
        }
    }

    public function test_wishlist_items_added_by_variant_keep_their_product(): void
    {
        $store = $this->openStore();
        $customer = $this->registered($store);
        $product = Product::factory()->create(['store_id' => $store->id, 'status' => 'active', 'visibility' => 'public', 'name' => 'Field Jacket', 'slug' => 'field-jacket', 'price_minor' => null]);
        $variant = ProductVariant::query()->create(['store_id' => $store->id, 'product_id' => $product->id, 'sku' => 'FJ-M', 'price_minor' => 12900, 'status' => 'active', 'option_values' => ['size' => 'M']]);
        $h = $this->as($customer);

        $this->postJson('/api/v1/wishlist', ['variant' => $variant->public_id], $h)->assertCreated()
            ->assertJsonPath('data.product_name', 'Field Jacket')
            ->assertJsonPath('data.product_slug', 'field-jacket')
            ->assertJsonPath('data.variant_options', ['size' => 'M'])
            ->assertJsonPath('data.availability', 'available')
            ->assertJsonPath('data.current_price_minor', 12900);

        $item = $this->getJson('/api/v1/wishlist', $h)->assertOk()->json('data.0.id');
        $this->postJson("/api/v1/wishlist/{$item}/move-to-cart", [], $h)->assertOk()->assertJsonPath('data.items.0.variant_id', $variant->public_id);
    }
}
