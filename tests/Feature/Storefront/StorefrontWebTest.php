<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Domain\Catalog\Models\Category;
use App\Domain\Domains\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B24 — the server-rendered storefront pages: Inertia props, SEO
 * written into <head> without JavaScript, index/noindex decisions, and
 * the same pages on a store's custom domain.
 */
final class StorefrontWebTest extends TestCase
{
    use InteractsWithStorefront, RefreshDatabase;

    public function test_the_home_page_renders_with_seo_in_the_html(): void
    {
        $store = $this->openStore(['name' => 'Acme Outfitters']);
        $this->product($store, ['name' => 'Field Jacket']);

        $response = $this->withoutVite()->get("/shop/{$store->slug}")->assertOk();

        $response->assertInertia(fn ($page) => $page->component('Storefront/Home')
            ->where('storefront.store.name', 'Acme Outfitters')
            ->where('storefront.base_path', "/shop/{$store->slug}")
            ->has('sections'));
        $html = $response->getContent();
        $this->assertStringContainsString('<title inertia>Acme Outfitters</title>', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://'.$store->slug.'.', $html);
        $this->assertStringContainsString('"@type":"Organization"', $html);
    }

    public function test_product_pages_embed_safe_structured_data(): void
    {
        $store = $this->openStore();
        $this->product($store, ['slug' => 'evil', 'name' => 'Bag </script><script>alert(1)</script>', 'price_minor' => 1999]);

        $html = $this->withoutVite()->get("/shop/{$store->slug}/products/evil")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Storefront/Product')->where('product.slug', 'evil'))
            ->getContent();

        $this->assertStringContainsString('"price":"19.99"', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('<meta name="robots" content="index, follow"', $html);
    }

    public function test_listing_refinements_and_private_pages_are_kept_out_of_the_index(): void
    {
        $store = $this->openStore();
        Category::factory()->create(['store_id' => $store->id, 'slug' => 'bags', 'name' => 'Bags']);
        $base = "/shop/{$store->slug}";

        $this->withoutVite()->get("{$base}/categories/bags")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Storefront/Catalog')->where('context.type', 'category')->where('seo.robots', 'index, follow'));
        $this->withoutVite()->get("{$base}/products?sort=price_asc")->assertOk()
            ->assertInertia(fn ($page) => $page->where('seo.robots', 'noindex, follow')->where('filters.sort', 'price_asc'));
        $this->withoutVite()->get("{$base}/search?q=bag")->assertOk()
            ->assertInertia(fn ($page) => $page->where('context.type', 'search')->where('seo.robots', 'noindex, follow'));
        // An invalid query value is ignored, never an error page.
        $this->withoutVite()->get("{$base}/products?sort=bogus&page=-4")->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters', []));
        $this->withoutVite()->get("{$base}/cart")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Storefront/Cart')->where('seo.robots', 'noindex, nofollow'));
        $this->withoutVite()->get("{$base}/checkout")->assertOk()->assertInertia(fn ($page) => $page->component('Storefront/Checkout'));
        $this->withoutVite()->get("{$base}/products/missing")->assertNotFound();
        $this->withoutVite()->get("{$base}/categories/missing")->assertNotFound();
    }

    public function test_checkout_offers_only_the_payment_methods_the_store_is_entitled_to(): void
    {
        $bare = $this->openStore(); // a package with no entitlements: no online ordering
        $this->withoutVite()->get("/shop/{$bare->slug}/checkout")->assertOk()
            ->assertInertia(fn ($page) => $page->where('payment_methods', []));

        $store = \App\Domain\Tenancy\Models\Store::factory()->create(['status' => 'active']);
        $this->entitle($store, ['orders.basic', 'payment.cod']);
        $this->withoutVite()->get("/shop/{$store->slug}/checkout")->assertOk()
            ->assertInertia(fn ($page) => $page->where('payment_methods', ['cod']));
        $this->getJson('/api/v1/storefront', $this->storefront($store))->assertOk()
            ->assertJsonPath('data.checkout.payment_methods', ['cod']);
    }

    public function test_a_verified_custom_domain_serves_the_storefront_at_its_root(): void
    {
        $store = $this->openStore(['name' => 'Acme']);
        Domain::query()->create([
            'store_id' => $store->id, 'hostname' => 'shop.acme.test', 'normalized_hostname' => 'shop.acme.test',
            'domain_type' => 'custom_domain', 'status' => 'active', 'is_primary' => false, 'ssl_status' => 'active',
        ]);
        $this->product($store, ['slug' => 'tote']);

        $this->withoutVite()->get('http://shop.acme.test/')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Storefront/Home')->where('storefront.base_path', ''));
        $this->withoutVite()->get('http://shop.acme.test/products/tote')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Storefront/Product'));
        $this->withoutVite()->get('http://unknown.example.test/')->assertNotFound();

        // The platform's own host still serves the admin app. (Full URL: the
        // test client builds relative URLs from the previous request's host.)
        $this->withoutVite()->get(rtrim((string) config('app.url'), '/').'/')->assertOk()->assertInertia(fn ($page) => $page->component('Welcome'));
    }
}
