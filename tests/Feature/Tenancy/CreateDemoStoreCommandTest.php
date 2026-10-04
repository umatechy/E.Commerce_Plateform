<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Orders\Models\Customer;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreStatus;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CreateDemoStoreCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PackageSeeder::class);
        \Illuminate\Support\Facades\Storage::fake('public');
    }

    public function test_it_creates_a_launched_store_with_three_categories_of_five_products(): void
    {
        $this->artisan('demo:store')->assertSuccessful();

        $store = Store::query()->where('name', 'Umar Techy Demo Store')->firstOrFail();
        $this->assertSame(StoreStatus::Active, $store->status);
        $this->assertSame('premium', Subscription::query()->withoutTenantScope()->where('store_id', $store->id)->firstOrFail()->package->code);
        $this->assertTrue(User::query()->where('email', 'demo.owner@example.com')->exists());

        $categories = Category::query()->withoutTenantScope()->where('store_id', $store->id)->get();
        $this->assertCount(3, $categories);
        foreach ($categories as $category) {
            $this->assertSame(5, Product::query()->withoutTenantScope()->where('primary_category_id', $category->id)->count());
            $this->assertSame(5, $category->products()->withoutGlobalScopes()->count());
        }

        $rose = Product::query()->withoutTenantScope()->where('store_id', $store->id)->where('sku', 'DEMO-ATR-001')->firstOrFail();
        $this->assertSame(250000, $rose->price_minor);
        $this->assertSame('PKR', $rose->currency);
        $this->assertSame(40, Inventory::query()->withoutTenantScope()->where('product_id', $rose->id)->value('on_hand'));
        $this->assertSame(0, Inventory::query()->withoutTenantScope()->where('store_id', $store->id)->whereHas('product', fn ($q) => $q->withoutGlobalScopes()->where('sku', 'DEMO-MCL-005'))->value('on_hand'));
        $this->assertTrue(Customer::query()->withoutTenantScope()->where('store_id', $store->id)->where('email', 'demo.customer@example.com')->whereNotNull('password')->exists());

        // Phase B36: every product has two pictures, and the Boutique theme is published with the demo home page.
        $this->assertSame(30, \App\Domain\Catalog\Models\ProductImage::query()->withoutTenantScope()->where('store_id', $store->id)->count());
        $theme = \App\Domain\Theme\Models\StoreTheme::query()->withoutTenantScope()->where('store_id', $store->id)->firstOrFail();
        $this->assertSame('boutique', $theme->theme->key);
        $this->assertContains('trust_badges', array_column($theme->published_config['sections'], 'type'));

        // The storefront shows it.
        $this->getJson('/api/v1/storefront/products', ['X-Store-Slug' => $store->slug])->assertOk()->assertJsonCount(15, 'data.products');
    }

    public function test_it_refuses_in_production_and_does_not_reuse_an_existing_account(): void
    {
        $this->app['env'] = 'production';
        $this->artisan('demo:store')->assertFailed();
        $this->assertSame(0, Store::query()->count());

        $this->app['env'] = 'testing';
        User::factory()->create(['email' => 'demo.owner@example.com']);
        $this->artisan('demo:store')->assertFailed();
        $this->assertSame(0, Store::query()->count());
    }
}
