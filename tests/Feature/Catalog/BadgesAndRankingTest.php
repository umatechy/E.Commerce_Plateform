<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase B43 — Module 06 §36 (badges), §37 (featured), §93 (sort priority),
 * Module 05 §19 (default sorting), Module 07 §17 (category ordering).
 */
final class BadgesAndRankingTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create(['status' => 'active']);
        $package = Package::factory()->create();
        $package->entitlements()->create(['key' => 'products.basic', 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        Subscription::factory()->for($this->store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role_id' => $this->systemRole($this->store, 'owner')->id, 'status' => 'active']);
        app(TenantContext::class)->resolveToStore($this->store->id);
    }

    private function setting(string $key, mixed $value): void
    {
        DB::table('store_settings')->updateOrInsert(['store_id' => $this->store->id, 'key' => $key], ['value' => json_encode([$value]), 'created_at' => now(), 'updated_at' => now()]);
        Cache::flush();
    }

    private function product(string $name, array $attributes = []): Product
    {
        return Product::factory()->create(['store_id' => $this->store->id, 'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'status' => 'active', 'visibility' => 'public', 'price_minor' => 10000, 'sale_price_minor' => null, 'published_at' => now()->subDays(60), ...$attributes]);
    }

    private function stock(Product $product, int $onHand): void
    {
        Inventory::query()->create([
            'store_id' => $this->store->id, 'product_id' => $product->id, 'on_hand' => $onHand, 'reserved' => 0,
            'warehouse_id' => Warehouse::query()->withoutTenantScope()->where('store_id', $this->store->id)->where('is_default', true)->value('id'),
        ]);
    }

    /** @return array<string, list<array<string, mixed>>> product name => badges, from the storefront listing */
    private function badges(string $query = ''): array
    {
        $this->app['auth']->forgetGuards();
        $products = $this->getJson("/api/v1/storefront/products{$query}", ['X-Store-Slug' => $this->store->slug])->assertOk()->json('data.products');

        return collect($products)->mapWithKeys(fn ($p) => [$p['name'] => $p['badges']])->all();
    }

    /** @return list<string> */
    private function names(string $query = ''): array
    {
        $this->app['auth']->forgetGuards();

        return array_column($this->getJson("/api/v1/storefront/products{$query}", ['X-Store-Slug' => $this->store->slug])->assertOk()->json('data.products'), 'name');
    }

    public function test_automatic_badges_follow_the_product_and_the_store_settings(): void
    {
        $this->setting('badges.low_stock', true);
        $this->setting('badges.max_per_product', 5);
        $this->product('Fresh', ['published_at' => now()->subDays(3)]);
        $this->product('Discounted', ['price_minor' => 10000, 'sale_price_minor' => 7500]);
        $variable = $this->product('Variable', ['price_minor' => null]);
        ProductVariant::query()->create(['store_id' => $this->store->id, 'product_id' => $variable->id, 'sku' => 'V1', 'price_minor' => 5000, 'sale_price_minor' => 4000, 'status' => 'active', 'option_values' => ['Size' => 'S']]);
        $gone = $this->product('Gone');
        $this->stock($gone, 0);
        $few = $this->product('Few');
        $this->stock($few, 3);
        $this->product('Star', ['is_featured' => true]);
        $popular = $this->product('Popular');
        $order = Order::factory()->for($this->store)->create(['status' => OrderStatus::Confirmed]);
        OrderItem::factory()->create(['store_id' => $this->store->id, 'order_id' => $order->id, 'product_id' => $popular->id, 'quantity' => 5]);
        $old = Order::factory()->for($this->store)->create(['status' => OrderStatus::Confirmed, 'created_at' => now()->subDays(90)]);
        OrderItem::factory()->create(['store_id' => $this->store->id, 'order_id' => $old->id, 'product_id' => $few->id, 'quantity' => 50]);

        $badges = $this->badges();
        $types = fn (string $name) => array_column($badges[$name], 'type');
        $this->assertSame(['new'], $types('Fresh'));
        $this->assertSame([['type' => 'sale', 'label' => null, 'tone' => 'danger', 'percent' => 25]], $badges['Discounted']);
        $this->assertSame(['sale'], $types('Variable'));
        $this->assertArrayNotHasKey('percent', $badges['Variable'][0], 'variants may differ: no single percent');
        $this->assertSame(['out_of_stock'], $types('Gone'));
        $this->assertSame(['low_stock'], $types('Few'), 'its sales are older than 30 days: not a bestseller');
        $this->assertSame(['featured'], $types('Star'));
        $this->assertSame(['bestseller'], $types('Popular'));

        // Switched off in the settings: gone. Percent can be hidden too.
        $this->setting('badges.new', false);
        $this->setting('badges.sale_percent', false);
        $badges = $this->badges();
        $this->assertSame([], $badges['Fresh']);
        $this->assertArrayNotHasKey('percent', $badges['Discounted'][0]);
    }

    public function test_own_badges_are_ordered_with_the_automatic_ones_cut_to_the_maximum_and_translated(): void
    {
        $product = $this->product('Shawl', ['sale_price_minor' => 9000, 'is_featured' => true, 'published_at' => now()]);
        $this->actingAs($this->owner);
        $handmade = $this->postJson('/api/v1/badges', ['label' => 'Handmade', 'tone' => 'success', 'priority' => 95])->assertCreated()->json('data.id');
        $eid = $this->postJson('/api/v1/badges', ['label' => 'Eid', 'priority' => 10])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/badges', ['label' => ' handmade '])->assertStatus(422);
        $this->postJson('/api/v1/badges', ['label' => 'X', 'tone' => 'rainbow'])->assertStatus(422);
        $this->putJson("/api/v1/products/{$product->public_id}", ['badge_ids' => [$handmade, $eid], 'sort_priority' => 5])->assertOk()
            ->assertJsonPath('data.sort_priority', 5)->assertJsonCount(2, 'data.badge_ids');

        // Handmade (95) > sale (90) > new (70) > featured (40) > Eid (10); default maximum 2.
        $this->assertSame([['custom', 'Handmade'], ['sale', null]], array_map(fn ($b) => [$b['type'], $b['label']], $this->badges()['Shawl']));
        $this->setting('badges.max_per_product', 10);
        $this->assertSame(['custom', 'sale', 'new', 'featured', 'custom'], array_column($this->badges()['Shawl'], 'type'));

        // An inactive badge is not shown; a translated one shows in its language.
        $this->actingAs($this->owner)->putJson("/api/v1/badges/{$eid}", ['is_active' => false])->assertOk();
        $this->setting('store.languages', ['en', 'ur']);
        $this->actingAs($this->owner)->putJson("/api/v1/translations/badge/{$handmade}", ['locale' => 'ur', 'fields' => ['label' => 'ہاتھ سے بنا']])->assertOk();
        $this->assertSame(['ہاتھ سے بنا', null, null, null], array_column($this->badges('?lang=ur')['Shawl'], 'label'));
        $this->app['auth']->forgetGuards();
        $this->assertSame('Handmade', $this->getJson('/api/v1/storefront/products/shawl', ['X-Store-Slug' => $this->store->slug])->json('data.product.badges.0.label'));
    }

    public function test_featured_order_is_deterministic_and_the_default_sort_comes_from_category_then_store(): void
    {
        $shoes = Category::factory()->for($this->store)->create(['name' => 'Shoes', 'slug' => 'shoes', 'status' => 'active', 'visibility' => 'public']);
        $in = ['primary_category_id' => $shoes->id];
        $this->product('Plain new', [...$in, 'published_at' => now()->subDay()]);
        $this->product('Featured low', [...$in, 'is_featured' => true, 'sort_priority' => 1]);
        $this->product('Featured high', [...$in, 'is_featured' => true, 'sort_priority' => 9]);
        $this->product('Pushed', [...$in, 'sort_priority' => 50, 'published_at' => now()->subDays(200)]);
        $this->product('Plain old', [...$in, 'published_at' => now()->subDays(100)]);

        $featuredOrder = ['Featured high', 'Featured low', 'Pushed', 'Plain new', 'Plain old'];
        $this->assertSame($featuredOrder, $this->names('?sort=featured'));
        $this->assertSame($featuredOrder, $this->names('?sort=featured'), 'same order every time');

        // No sort chosen: the store default (newest), then the store sets featured.
        $this->assertSame('Plain new', $this->names()[0]);
        $this->setting('catalog.default_sort', 'featured');
        $listing = $this->getJson('/api/v1/storefront/products', ['X-Store-Slug' => $this->store->slug])->json('data');
        $this->assertSame([$featuredOrder, 'featured'], [array_column($listing['products'], 'name'), $listing['sort']]);

        // The category opens in its own order; a chosen sort always wins.
        $this->actingAs($this->owner)->putJson("/api/v1/categories/{$shoes->id}", ['name' => 'Shoes', 'default_sort' => 'name'])->assertOk()->assertJsonPath('data.default_sort', 'name');
        $this->assertSame(['Featured high', 'Featured low', 'Plain new', 'Plain old', 'Pushed'], $this->names('?category=shoes'));
        $this->assertSame('Pushed', $this->names('?category=shoes&sort=price_asc&sort=featured')[2]);
        $this->actingAs($this->owner)->putJson("/api/v1/categories/{$shoes->id}", ['name' => 'Shoes', 'default_sort' => 'cheapest'])->assertStatus(422);
        $this->actingAs($this->owner)->putJson('/api/v1/store/settings/catalog.default_sort', ['value' => 'random'])->assertStatus(422);
    }

    public function test_search_lifts_featured_products_among_equal_matches_when_the_store_wants_it(): void
    {
        // The featured one is the oldest, so the boost is visible against the id order.
        $this->product('Silk scarf red', ['is_featured' => true]);
        $this->product('Silk scarf blue');
        $this->product('Cotton silk mix', ['is_featured' => true, 'sort_priority' => 99]);

        // Name matches first (a featured product never jumps over a better match), then featured among them.
        $this->assertSame(['Silk scarf red', 'Silk scarf blue', 'Cotton silk mix'], $this->names('?q=silk'));
        $this->setting('catalog.featured_in_search', false);
        $this->assertSame(['Silk scarf blue', 'Silk scarf red', 'Cotton silk mix'], $this->names('?q=silk'), 'without the boost: newest first among equal matches');
        // A chosen sort is respected on a search too.
        $this->assertSame(['Cotton silk mix', 'Silk scarf blue', 'Silk scarf red'], $this->names('?q=silk&sort=name'));
    }

    public function test_badges_and_priority_in_bulk_csv_and_duplicates_with_permissions(): void
    {
        $a = $this->product('Alpha', ['sku' => 'A-1']);
        $b = $this->product('Beta', ['sku' => 'B-1']);
        $this->actingAs($this->owner);
        $badge = $this->postJson('/api/v1/badges', ['label' => 'Organic'])->json('data.id');

        $this->postJson('/api/v1/products/bulk', ['action' => 'add_badge', 'products' => [$a->public_id, $b->public_id], 'params' => ['badge_id' => $badge]])->assertOk()->assertJsonPath('data.affected', 2);
        $this->postJson('/api/v1/products/bulk', ['action' => 'add_badge', 'products' => [$a->public_id], 'params' => ['badge_id' => $badge]])->assertOk()->assertJsonPath('data.affected', 0);
        $this->postJson('/api/v1/products/bulk', ['action' => 'set_sort_priority', 'products' => [$b->public_id], 'params' => ['sort_priority' => 2000]])->assertStatus(422);
        $this->postJson('/api/v1/products/bulk', ['action' => 'set_sort_priority', 'products' => [$b->public_id], 'params' => ['sort_priority' => 7]])->assertOk();
        $this->assertSame(7, $b->refresh()->sort_priority);

        // CSV: out and back in.
        $csv = $this->get('/api/v1/products/export')->streamedContent();
        $this->assertStringContainsString('Organic', $csv);
        $file = UploadedFile::fake()->createWithContent('p.csv', "sku,sort_priority,badges\nA-1,3,Organic\nB-1,x,Vegan\n");
        $preview = $this->post('/api/v1/products/import', ['file' => $file], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertSame(['update', 'invalid'], array_column($preview['rows'], 'status'));
        $this->assertStringContainsString('no badge "Vegan"', implode(' ', $preview['rows'][1]['messages']));
        $this->postJson("/api/v1/products/import/{$preview['id']}/confirm")->assertOk();
        $this->assertSame(3, $a->refresh()->sort_priority);

        // A duplicate keeps the badges and priority.
        $copy = $this->postJson("/api/v1/products/{$a->public_id}/duplicate")->assertCreated()->json('data.id');
        $this->assertSame([$badge], $this->getJson("/api/v1/products/{$copy}")->json('data.badge_ids'));

        // Another store's badge cannot be used; a viewer cannot define badges.
        $other = Store::factory()->create();
        $foreign = DB::table('badges')->insertGetId(['store_id' => $other->id, 'label' => 'Theirs', 'tone' => 'accent', 'priority' => 50, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        app(TenantContext::class)->resolveToStore($this->store->id);
        $this->putJson("/api/v1/products/{$a->public_id}", ['badge_ids' => [$foreign]])->assertStatus(422);
        $role = Role::factory()->for($this->store)->create(['slug' => 'viewer-'.uniqid()]);
        DB::table('permission_role')->insert(['role_id' => $role->id, 'permission_id' => Permission::query()->firstOrCreate(['key' => 'products.view'], ['group' => 'products', 'description' => 'x'])->id]);
        $viewer = User::factory()->create();
        $this->store->users()->attach($viewer, ['role_id' => $role->id, 'status' => 'active']);
        $this->actingAs($viewer)->getJson('/api/v1/badges')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/badges', ['label' => 'Nope'])->assertForbidden();
    }
}
