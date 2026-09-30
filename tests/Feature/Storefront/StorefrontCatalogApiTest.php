<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Seo\Models\ContentPage;
use App\Domain\Theme\Models\StoreTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase B24 — Module 05 public catalog API: visibility rules, filters,
 * sorting, product detail, navigation, pages, suggestions and caching.
 */
final class StorefrontCatalogApiTest extends TestCase
{
    use InteractsWithStorefront, RefreshDatabase;

    /** @return list<string> */
    private function names(\Illuminate\Testing\TestResponse $response): array
    {
        return array_column($response->assertOk()->json('data.products'), 'name');
    }

    public function test_browsing_lists_only_active_browsable_products_of_this_store(): void
    {
        $other = $this->openStore();
        $this->product($other, ['name' => 'Foreign']);
        $store = $this->openStore();
        $this->product($store, ['name' => 'Public', 'published_at' => now()->subDay()]);
        $this->product($store, ['name' => 'Catalog only', 'visibility' => 'catalog_only', 'published_at' => now()]);
        $this->product($store, ['name' => 'Search only', 'visibility' => 'search_only']);
        $this->product($store, ['name' => 'Draft', 'status' => 'draft']);
        $this->product($store, ['name' => 'Hidden', 'visibility' => 'hidden']);

        $response = $this->getJson('/api/v1/storefront/products', $this->storefront($store));

        $this->assertSame(['Catalog only', 'Public'], $this->names($response));
        $card = $response->json('data.products.0');
        $this->assertSame(26, strlen($card['id']));
        $this->assertArrayNotHasKey('cost_price_minor', $card);
        $this->assertStringNotContainsString('"store_id"', $response->getContent());
    }

    public function test_search_uses_search_visibility_and_treats_wildcards_literally(): void
    {
        $store = $this->openStore();
        $this->product($store, ['name' => 'Linen Shirt', 'visibility' => 'search_only']);
        $this->product($store, ['name' => 'Linen Scarf', 'visibility' => 'catalog_only']);
        $this->product($store, ['name' => 'Wool Coat']);

        $this->assertSame(['Linen Shirt'], $this->names($this->getJson('/api/v1/storefront/products?q=linen', $this->storefront($store))));
        $this->assertSame([], $this->names($this->getJson('/api/v1/storefront/products?q=%25', $this->storefront($store))));
        $this->assertSame(['Wool Coat'], $this->names($this->getJson('/api/v1/storefront/products?q=wool+coat', $this->storefront($store))));
    }

    public function test_category_brand_price_and_stock_filters(): void
    {
        $store = $this->openStore();
        $parent = Category::factory()->create(['store_id' => $store->id, 'name' => 'Clothing', 'slug' => 'clothing']);
        $child = Category::factory()->create(['store_id' => $store->id, 'name' => 'Shirts', 'slug' => 'shirts', 'parent_id' => $parent->id]);
        $hidden = Category::factory()->create(['store_id' => $store->id, 'slug' => 'secret', 'visibility' => 'hidden']);
        $brand = Brand::factory()->create(['store_id' => $store->id, 'slug' => 'northwind']);

        $shirt = $this->product($store, ['name' => 'Shirt', 'price_minor' => 3000, 'sale_price_minor' => 2000, 'brand_id' => $brand->id]);
        $shirt->categories()->attach($child->id);
        $jacket = $this->product($store, ['name' => 'Jacket', 'price_minor' => 9000, 'primary_category_id' => $parent->id]);
        $this->stock($jacket, null, 3, 3); // everything reserved: sold out
        $this->product($store, ['name' => 'Mug', 'price_minor' => 1500, 'primary_category_id' => $hidden->id]);
        $tee = $this->product($store, ['name' => 'Tee', 'price_minor' => null]);
        $this->variant($tee, ['size' => 'S'], 1200);
        $this->variant($tee, ['size' => 'XL'], 1800, ['sale_price_minor' => 900]);
        $h = $this->storefront($store);

        $this->assertEqualsCanonicalizing(['Shirt', 'Jacket'], $this->names($this->getJson('/api/v1/storefront/products?category=clothing', $h)));
        $this->assertSame([], $this->names($this->getJson('/api/v1/storefront/products?category=secret', $h)));
        $this->assertSame(['Shirt'], $this->names($this->getJson('/api/v1/storefront/products?brand=northwind', $h)));
        // Effective prices: Shirt 2000 (sale), Tee from 900 (variant sale), Mug 1500, Jacket 9000.
        $this->assertEqualsCanonicalizing(['Shirt', 'Mug'], $this->names($this->getJson('/api/v1/storefront/products?min_price=1000&max_price=2500', $h)));
        $this->assertSame(['Tee', 'Mug', 'Shirt', 'Jacket'], $this->names($this->getJson('/api/v1/storefront/products?sort=price_asc', $h)));
        $this->assertNotContains('Jacket', $this->names($this->getJson('/api/v1/storefront/products?in_stock=1', $h)));

        $tee = collect($this->getJson('/api/v1/storefront/products?q=tee', $h)->json('data.products'))->sole();
        $this->assertSame(['currency' => 'USD', 'amount_minor' => 900, 'max_amount_minor' => 1200, 'compare_at_minor' => null, 'on_sale' => true], $tee['price']);

        $this->getJson('/api/v1/storefront/products?sort=random&per_page=500', $h)->assertStatus(422)->assertJsonValidationErrors(['sort', 'per_page']);
    }

    public function test_pagination(): void
    {
        $store = $this->openStore();
        foreach (range(1, 5) as $i) {
            $this->product($store, ['name' => "Item {$i}"]);
        }

        $this->getJson('/api/v1/storefront/products?per_page=2&page=3&sort=name', $this->storefront($store))
            ->assertOk()
            ->assertJsonPath('data.pagination', ['page' => 3, 'per_page' => 2, 'total' => 5, 'last_page' => 3])
            ->assertJsonPath('data.products.0.name', 'Item 5');
    }

    public function test_product_detail_publishes_options_and_availability_but_no_secrets(): void
    {
        $store = $this->openStore();
        $category = Category::factory()->create(['store_id' => $store->id, 'name' => 'Shirts', 'slug' => 'shirts']);
        $product = $this->product($store, [
            'slug' => 'oxford-shirt', 'name' => 'Oxford Shirt', 'price_minor' => null, 'cost_price_minor' => 700,
            'primary_category_id' => $category->id,
            'description' => '<p>Soft <strong>cotton</strong></p><script>steal()</script><a href="jav&#x09;ascript:alert(1)">x</a>',
        ]);
        $blue = $this->variant($product, ['color' => 'blue', 'size' => 'M'], 4000, ['cost_price_minor' => 700]);
        $white = $this->variant($product, ['color' => 'white', 'size' => 'M'], 4000);
        $red = $this->variant($product, ['color' => 'red', 'size' => 'L'], 4000);
        $this->stock($product, $blue, 50);
        $this->stock($product, $white, 2);
        $this->stock($product, $red, 1, 1);

        $response = $this->getJson('/api/v1/storefront/products/oxford-shirt', $this->storefront($store))->assertOk();

        $response->assertJsonPath('data.product.options', [['name' => 'color', 'values' => ['blue', 'white', 'red']], ['name' => 'size', 'values' => ['M', 'L']]])
            ->assertJsonPath('data.product.variants.0.availability', 'in_stock')
            ->assertJsonPath('data.product.variants.1.availability', 'low_stock')
            ->assertJsonPath('data.product.variants.2.availability', 'out_of_stock')
            ->assertJsonPath('data.product.variants.2.purchasable', false)
            ->assertJsonPath('data.product.purchasable', true)
            ->assertJsonPath('data.product.breadcrumbs', [['name' => 'Shirts', 'slug' => 'shirts']])
            ->assertJsonPath('data.product.description_html', '<p>Soft <strong>cotton</strong></p><a>x</a>')
            ->assertJsonPath('data.seo.structured_data.0.@type', 'Product')
            ->assertJsonPath('data.seo.structured_data.0.offers.price', '40.00');
        $this->assertStringNotContainsString('cost_price', $response->getContent());
        $this->assertStringNotContainsString('"on_hand"', $response->getContent());
        $this->assertStringNotContainsString('50', json_encode($response->json('data.product.variants')));
    }

    public function test_catalog_only_products_open_but_cannot_be_bought_and_hidden_ones_do_not_exist(): void
    {
        $store = $this->openStore();
        $this->product($store, ['slug' => 'display-piece', 'visibility' => 'catalog_only']);
        $this->product($store, ['slug' => 'secret', 'visibility' => 'private']);
        $foreign = $this->openStore();
        $this->product($foreign, ['slug' => 'foreign']);
        $h = $this->storefront($store);

        $this->getJson('/api/v1/storefront/products/display-piece', $h)->assertOk()->assertJsonPath('data.product.purchasable', false);
        $this->getJson('/api/v1/storefront/products/secret', $h)->assertNotFound()->assertJsonPath('code', 'product_not_found');
        $this->getJson('/api/v1/storefront/products/foreign', $h)->assertNotFound();
    }

    public function test_categories_brands_and_pages(): void
    {
        $store = $this->openStore();
        $root = Category::factory()->create(['store_id' => $store->id, 'name' => 'Home', 'slug' => 'home-goods']);
        $leaf = Category::factory()->create(['store_id' => $store->id, 'name' => 'Kitchen', 'slug' => 'kitchen', 'parent_id' => $root->id]);
        Category::factory()->create(['store_id' => $store->id, 'name' => 'Draft cat', 'status' => 'draft']);
        $brand = Brand::factory()->create(['store_id' => $store->id, 'name' => 'Acme']);
        Brand::factory()->create(['store_id' => $store->id, 'name' => 'No products']);
        $pan = $this->product($store, ['brand_id' => $brand->id, 'primary_category_id' => $leaf->id]);
        $pan->categories()->attach($root->id); // in both: counted once
        ContentPage::factory()->create(['store_id' => $store->id, 'slug' => 'about', 'title' => 'About', 'status' => 'published', 'body' => '<p>Hi</p><img src=x onerror=alert(1)>']);
        ContentPage::factory()->create(['store_id' => $store->id, 'slug' => 'draft-page']);
        $h = $this->storefront($store);

        $tree = $this->getJson('/api/v1/storefront/categories', $h)->assertOk()->json('data');
        $this->assertSame(['Home'], array_column($tree, 'name'));
        $this->assertSame(1, $tree[0]['product_count']);
        $this->assertSame('Kitchen', $tree[0]['children'][0]['name']);

        $this->getJson('/api/v1/storefront/brands', $h)->assertOk()->assertJsonPath('data', [['slug' => $brand->slug, 'name' => 'Acme', 'description' => null]]);
        $this->getJson('/api/v1/storefront/pages/about', $h)->assertOk()->assertJsonPath('data.page.body_html', '<p>Hi</p>');
        $this->getJson('/api/v1/storefront/pages/draft-page', $h)->assertNotFound();
        $this->getJson('/api/v1/storefront/categories/kitchen', $h)->assertOk()->assertJsonPath('data.category.product_count', 1);
    }

    public function test_home_follows_the_published_theme(): void
    {
        $store = $this->openStore(['name' => 'Acme Outfitters']);
        $this->product($store, ['name' => 'Newest']);
        StoreTheme::query()->where('store_id', $store->id)->update(['published_config' => json_encode([
            'tokens' => ['primary' => '#FF0000'],
            'branding' => ['tagline' => 'Built to last'],
            'sections' => [
                ['type' => 'announcement_bar', 'position' => 0, 'is_visible' => true, 'config' => ['message' => 'Free shipping over $50']],
                ['type' => 'featured_products', 'position' => 2, 'is_visible' => true, 'config' => ['heading' => 'Fresh', 'limit' => 1]],
                ['type' => 'hero', 'position' => 1, 'is_visible' => true, 'config' => ['heading' => 'Welcome']],
                ['type' => 'promotional_banner', 'position' => 3, 'is_visible' => false, 'config' => ['heading' => 'Hidden']],
            ],
        ])]);
        app(\App\Domain\Storefront\Services\StorefrontCache::class)->bump($store->id); // a raw update fires no model event

        $this->getJson('/api/v1/storefront', $this->storefront($store))
            ->assertOk()
            ->assertJsonPath('data.shell.store.name', 'Acme Outfitters')
            ->assertJsonPath('data.shell.store.tagline', 'Built to last')
            ->assertJsonPath('data.shell.theme.tokens.primary', '#FF0000')
            ->assertJsonPath('data.shell.announcement', 'Free shipping over $50')
            ->assertJsonPath('data.home.sections.0.type', 'hero')
            ->assertJsonPath('data.home.sections.1.heading', 'Fresh')
            ->assertJsonPath('data.home.sections.1.products.0.name', 'Newest')
            ->assertJsonCount(2, 'data.home.sections');
    }

    public function test_a_new_store_on_the_default_theme_still_gets_a_home_page(): void
    {
        $store = $this->openStore();

        $types = array_column($this->getJson('/api/v1/storefront', $this->storefront($store))->assertOk()->json('data.home.sections'), 'type');

        $this->assertSame(['hero', 'featured_categories', 'featured_products'], $types);
    }

    public function test_suggestions(): void
    {
        $store = $this->openStore();
        $this->product($store, ['name' => 'Canvas Tote']);
        $this->product($store, ['name' => 'Big Canvas Bag']);
        Category::factory()->create(['store_id' => $store->id, 'name' => 'Canvas goods']);

        $response = $this->getJson('/api/v1/storefront/search/suggest?q=canv', $this->storefront($store))->assertOk();

        $this->assertSame(['category', 'product', 'product'], array_column($response->json('data'), 'type'));
        $this->getJson('/api/v1/storefront/search/suggest?q=c', $this->storefront($store))->assertStatus(422);
    }

    public function test_listings_are_cached_and_invalidated_by_catalog_changes(): void
    {
        $store = $this->openStore();
        $product = $this->product($store, ['name' => 'Original']);
        $h = $this->storefront($store);
        $this->assertSame(['Original'], $this->names($this->getJson('/api/v1/storefront/products', $h)));

        // A write that bypasses model events is not seen: the cached page is served.
        DB::table('products')->where('id', $product->id)->update(['name' => 'Sneaky']);
        $this->assertSame(['Original'], $this->names($this->getJson('/api/v1/storefront/products', $h)));

        // A normal catalog change bumps the store's cache version at once.
        Product::query()->findOrFail($product->id)->update(['name' => 'Renamed']);
        $this->assertSame(['Renamed'], $this->names($this->getJson('/api/v1/storefront/products', $h)));
    }
}
