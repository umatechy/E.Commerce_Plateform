<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Collection;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Promotions\Models\Promotion;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase B39 — gap G15 part 1: collections (manual, rule-based, scheduled),
 * tags, featured products, related products, duplication, bulk changes and
 * promotions aimed at a collection (Module 05 §16, Module 06 §33–38, §46–47,
 * §60, Module 14 §9).
 */
final class CollectionsAndMerchandisingTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->store, $this->owner] = $this->storeWithOwner();
    }

    /** @return array{0: Store, 1: User} */
    private function storeWithOwner(?int $maxProducts = null): array
    {
        $store = Store::factory()->create(['status' => 'active']);
        $package = Package::factory()->create();
        foreach (['products.basic', 'orders.basic'] as $key) {
            $package->entitlements()->create(['key' => $key, 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        }
        if ($maxProducts !== null) {
            $package->entitlements()->create(['key' => 'max_products', 'type' => EntitlementType::UsageLimit, 'limit_value' => $maxProducts]);
        }
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);
        app(TenantContext::class)->resolveToStore($store->id);

        return [$store, $owner];
    }

    /** @param list<string> $keys */
    private function staffWith(array $keys): User
    {
        $role = Role::factory()->for($this->store)->create(['slug' => 'custom-'.uniqid()]);
        foreach ($keys as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['group' => 'catalog', 'description' => 'x']);
            DB::table('permission_role')->insert(['role_id' => $role->id, 'permission_id' => $permission->id]);
        }
        $user = User::factory()->create();
        $this->store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    private function product(array $attributes = [], ?Store $store = null): Product
    {
        return Product::factory()->create(['store_id' => ($store ?? $this->store)->id, 'status' => 'active', 'visibility' => 'public', 'price_minor' => 10000, ...$attributes]);
    }

    /** @return list<string> product names on a storefront listing, in order */
    private function listed(string $query): array
    {
        $this->app['auth']->forgetGuards();

        return array_column($this->getJson("/api/v1/storefront/products{$query}", ['X-Store-Slug' => $this->store->slug])->assertOk()->json('data.products'), 'name');
    }

    public function test_a_manual_collection_lists_its_products_in_the_chosen_order_and_only_while_live(): void
    {
        $a = $this->product(['name' => 'Alpha']);
        $b = $this->product(['name' => 'Bravo']);
        $this->product(['name' => 'Charlie']);
        $hidden = $this->product(['name' => 'Draft one', 'status' => 'draft']);

        $id = $this->actingAs($this->owner)->postJson('/api/v1/collections', ['name' => 'Eid Picks', 'type' => 'manual'])
            ->assertCreated()->assertJsonPath('data.slug', 'eid-picks')->json('data.id');
        $this->putJson("/api/v1/collections/{$id}/products", ['products' => [$b->public_id, $a->public_id, $hidden->public_id]])->assertOk();

        $this->assertSame(['Bravo', 'Alpha'], $this->listed('?collection=eid-picks'), 'own order; a draft product is never shown');
        $this->assertSame('Eid Picks', $this->getJson('/api/v1/storefront/collections/eid-picks', ['X-Store-Slug' => $this->store->slug])->assertOk()->json('data.collection.name'));
        $this->withoutVite()->get("/shop/{$this->store->slug}/collections/eid-picks")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Storefront/Catalog')->where('context.type', 'collection')->where('listing.pagination.total', 2));

        // Scheduled for later: not live — no page, and it lists nothing rather than everything.
        $this->actingAs($this->owner)->putJson("/api/v1/collections/{$id}", ['starts_at' => now()->addDay()->toIso8601String()])->assertOk()->assertJsonPath('data.is_live', false);
        $this->assertSame([], $this->listed('?collection=eid-picks'));
        $this->getJson('/api/v1/storefront/collections/eid-picks', ['X-Store-Slug' => $this->store->slug])->assertNotFound();
        $this->withoutVite()->get("/shop/{$this->store->slug}/collections/eid-picks")->assertNotFound();
    }

    public function test_a_rule_based_collection_follows_its_conditions_and_only_accepts_this_stores_items(): void
    {
        $category = Category::factory()->for($this->store)->create(['status' => 'active', 'visibility' => 'public']);
        $cheap = $this->product(['name' => 'Cheap', 'price_minor' => 500, 'primary_category_id' => $category->id]);
        $this->product(['name' => 'Dear', 'price_minor' => 90000, 'primary_category_id' => $category->id]);
        $this->product(['name' => 'Elsewhere', 'price_minor' => 400]);
        $this->actingAs($this->owner)->putJson("/api/v1/products/{$cheap->public_id}", ['tags' => ['Handmade', 'Gift']])->assertOk();
        $tagId = DB::table('tags')->where('store_id', $this->store->id)->where('slug', 'handmade')->value('id');

        $this->postJson('/api/v1/collections', ['name' => 'Under 1000', 'type' => 'rule', 'sort' => 'price_asc', 'rules' => [
            ['field' => 'category', 'value' => [$category->id]], ['field' => 'price_max', 'value' => 1000],
        ]])->assertCreated();
        $this->assertSame(['Cheap'], $this->listed('?collection=under-1000'));

        $this->actingAs($this->owner)->postJson('/api/v1/collections', ['name' => 'Handmade or dear', 'type' => 'rule', 'match' => 'any', 'rules' => [
            ['field' => 'tag', 'value' => [(int) $tagId]], ['field' => 'price_min', 'value' => 50000],
        ]])->assertCreated();
        $this->assertEqualsCanonicalizing(['Cheap', 'Dear'], $this->listed('?collection=handmade-or-dear'));
        $this->assertSame(['Cheap'], $this->listed('?tag=handmade'));

        // Another store's category, an unknown field, a column name: refused.
        [$other] = $this->storeWithOwner();
        $foreign = Category::factory()->for($other)->create();
        app(TenantContext::class)->resolveToStore($this->store->id);
        $this->actingAs($this->owner)->postJson('/api/v1/collections', ['name' => 'X', 'type' => 'rule', 'rules' => [['field' => 'category', 'value' => [$foreign->id]]]])->assertStatus(422);
        $this->postJson('/api/v1/collections', ['name' => 'Y', 'type' => 'rule', 'rules' => [['field' => 'cost_price_minor', 'value' => 1]]])->assertStatus(422);
        $this->postJson('/api/v1/collections', ['name' => 'Z', 'type' => 'rule', 'rules' => []])->assertStatus(422);
    }

    public function test_collections_need_their_permission_and_stay_in_their_store(): void
    {
        $viewer = $this->staffWith(['products.view']);
        $this->actingAs($this->owner)->postJson('/api/v1/collections', ['name' => 'Summer', 'type' => 'manual'])->assertCreated();

        $this->actingAs($viewer)->getJson('/api/v1/collections')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/collections', ['name' => 'Nope', 'type' => 'manual'])->assertForbidden();

        $manager = $this->staffWith(['collections.manage']);
        $this->actingAs($manager)->postJson('/api/v1/collections', ['name' => 'Winter', 'type' => 'manual'])->assertCreated();

        // Store B sees none of store A's collections and cannot open one.
        [$other, $otherOwner] = $this->storeWithOwner();
        $id = Collection::query()->withoutTenantScope()->where('store_id', $this->store->id)->value('public_id');
        $this->actingAs($otherOwner)->getJson('/api/v1/collections')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/collections/{$id}")->assertNotFound();
        $this->putJson("/api/v1/collections/{$id}", ['name' => 'Taken'])->assertNotFound();
    }

    public function test_tags_featured_and_collections_are_set_on_the_product_and_feed_the_home_section(): void
    {
        $product = $this->product(['name' => 'Silk Scarf']);
        $this->product(['name' => 'Plain Socks']);
        $collection = $this->actingAs($this->owner)->postJson('/api/v1/collections', ['name' => 'Gifts', 'type' => 'manual'])->json('data.id');

        $this->putJson("/api/v1/products/{$product->public_id}", ['is_featured' => true, 'tags' => ['Silk', ' silk ', 'Gift'], 'collection_ids' => [$collection]])
            ->assertOk()
            ->assertJsonPath('data.is_featured', true)
            ->assertJsonPath('data.tags', ['Gift', 'Silk'])
            ->assertJsonPath('data.collection_ids', [$collection]);
        $this->getJson('/api/v1/tags')->assertOk()->assertJsonCount(2, 'data');

        $this->putJson("/api/v1/products/{$product->public_id}", ['tags' => array_map(fn ($i) => "t{$i}", range(1, 21))])->assertStatus(422);

        // Without collections.manage the product's collections cannot be changed.
        $editor = $this->staffWith(['products.view', 'products.update']);
        $this->actingAs($editor)->putJson("/api/v1/products/{$product->public_id}", ['collection_ids' => []])->assertForbidden();
        $this->putJson("/api/v1/products/{$product->public_id}", ['is_featured' => false])->assertOk();
        $this->putJson("/api/v1/products/{$product->public_id}", ['is_featured' => true])->assertOk();

        $theme = app(\App\Domain\Theme\Services\ThemeConfigValidator::class);
        $validate = new \ReflectionMethod($theme, 'validateSectionConfig');
        $this->assertSame(['limit' => 4, 'source' => 'featured'], $validate->invoke($theme, \App\Domain\Theme\Models\SectionType::FeaturedProducts, ['source' => 'featured', 'limit' => 4]));
        try {
            $validate->invoke($theme, \App\Domain\Theme\Models\SectionType::FeaturedProducts, ['source' => 'everything']);
            $this->fail('An unknown source must be refused.');
        } catch (\App\Domain\Theme\Exceptions\InvalidThemeConfigException) {
        }
        $experience = app(\App\Domain\Storefront\Services\StorefrontExperience::class);
        $section = (new \ReflectionMethod($experience, 'featuredSection'))->invoke($experience, 'featured_products', ['source' => 'featured', 'limit' => 4]);
        $this->assertSame(['Silk Scarf'], array_column($section['products'], 'name'));
        $section = (new \ReflectionMethod($experience, 'featuredSection'))->invoke($experience, 'featured_products', ['source' => 'collection', 'collection' => 'gifts']);
        $this->assertSame(['Silk Scarf'], array_column($section['products'], 'name'));
        $section = (new \ReflectionMethod($experience, 'featuredSection'))->invoke($experience, 'featured_products', ['source' => 'collection', 'collection' => 'no-such']);
        $this->assertSame([], $section['products']);
    }

    public function test_related_products_are_chosen_per_kind_and_shown_on_the_product_page(): void
    {
        $category = Category::factory()->for($this->store)->create(['status' => 'active', 'visibility' => 'public']);
        $main = $this->product(['name' => 'Kurta', 'slug' => 'kurta', 'primary_category_id' => $category->id]);
        $same = $this->product(['name' => 'Same category', 'primary_category_id' => $category->id]);
        $shawl = $this->product(['name' => 'Shawl']);
        $better = $this->product(['name' => 'Silk Kurta']);
        $draft = $this->product(['name' => 'Unfinished', 'status' => 'draft']);

        // Nothing chosen: others of its category.
        $this->withoutVite()->get("/shop/{$this->store->slug}/products/kurta")->assertOk()
            ->assertInertia(fn ($page) => $page->where('related.0.name', 'Same category')->where('cross_sell', []));

        $this->actingAs($this->owner)->putJson("/api/v1/products/{$main->public_id}/relations", ['relations' => [
            'related' => [$shawl->public_id, $draft->public_id], 'cross_sell' => [$shawl->public_id], 'up_sell' => [$better->public_id],
        ]])->assertOk()->assertJsonPath('data.up_sell.0.name', 'Silk Kurta');
        $this->putJson("/api/v1/products/{$main->public_id}/relations", ['relations' => ['related' => [$main->public_id]]])->assertStatus(422);
        $this->putJson("/api/v1/products/{$main->public_id}/relations", ['relations' => ['friends' => []]])->assertStatus(422);
        $foreign = $this->product([], $this->storeWithOwner()[0]);
        app(TenantContext::class)->resolveToStore($this->store->id);
        $this->actingAs($this->owner)->putJson("/api/v1/products/{$main->public_id}/relations", ['relations' => ['related' => [$foreign->public_id]]])->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $this->withoutVite()->get("/shop/{$this->store->slug}/products/kurta")->assertOk()
            ->assertInertia(fn ($page) => $page->where('related', fn ($r) => array_column(collect($r)->all(), 'name') === ['Shawl'])
                ->where('cross_sell.0.name', 'Shawl')->where('up_sell.0.name', 'Silk Kurta'));

        // No related ones chosen: the category fallback leaves out what "goes well with" already shows.
        $this->actingAs($this->owner)->putJson("/api/v1/products/{$main->public_id}/relations", ['relations' => ['related' => [], 'cross_sell' => [$same->public_id]]])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withoutVite()->get("/shop/{$this->store->slug}/products/kurta")->assertOk()
            ->assertInertia(fn ($page) => $page->where('cross_sell.0.name', 'Same category')->where('related', []));
    }

    public function test_a_duplicate_is_a_hidden_draft_copy_and_counts_against_the_product_limit(): void
    {
        [$store, $owner] = $this->storeWithOwner(maxProducts: 2);
        $category = Category::factory()->for($store)->create();
        $original = $this->actingAs($owner)->postJson('/api/v1/products', [
            'type' => 'simple', 'name' => 'Lawn Suit', 'sku' => 'LAWN-1', 'price_minor' => 450000, 'status' => 'active',
            'category_ids' => [$category->id], 'tags' => ['Summer'],
        ])->assertCreated()->json('data.id');

        $copy = $this->postJson("/api/v1/products/{$original}/duplicate")->assertCreated()
            ->assertJsonPath('data.name', 'Lawn Suit (copy)')->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.visibility', 'hidden')->assertJsonPath('data.sku', null)->json('data.id');
        $this->assertNotSame($original, $copy);
        $this->assertSame(['Summer'], $this->getJson("/api/v1/products/{$copy}")->json('data.tags'));
        $this->assertSame([$category->id], $this->getJson("/api/v1/products/{$copy}")->json('data.category_ids'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.duplicated']);

        $this->postJson("/api/v1/products/{$original}/duplicate")->assertForbidden()->assertJsonPath('code', 'usage_limit_exceeded');
    }

    public function test_bulk_changes_apply_per_product_with_the_same_rules_as_one_change(): void
    {
        [$store, $owner] = $this->storeWithOwner(maxProducts: 3);
        $priced = $this->product(['name' => 'Priced', 'status' => 'draft', 'price_minor' => 1000], $store);
        $unpriced = $this->product(['name' => 'Unpriced', 'status' => 'draft', 'price_minor' => null], $store);
        $archived = $this->product(['name' => 'Old', 'status' => 'archived', 'price_minor' => 2000], $store);
        $foreign = $this->product([], $this->storeWithOwner()[0]);
        app(TenantContext::class)->resolveToStore($store->id);
        $this->actingAs($owner);

        $result = $this->postJson('/api/v1/products/bulk', ['action' => 'publish', 'products' => [$priced->public_id, $unpriced->public_id, $foreign->public_id]])->assertOk()->json('data');
        $this->assertSame(1, $result['affected']);
        $this->assertEqualsCanonicalizing(['Unpriced', ''], array_column($result['skipped'], 'name'), 'no price; another store\'s product is not found');
        $this->assertSame('active', $priced->refresh()->status->value);

        $this->postJson('/api/v1/products/bulk', ['action' => 'set_sale_percent', 'products' => [$priced->public_id], 'params' => ['percent' => 25]])->assertOk()->assertJsonPath('data.affected', 1);
        $this->assertSame(750, $priced->refresh()->sale_price_minor);
        $this->postJson('/api/v1/products/bulk', ['action' => 'set_price', 'products' => [$priced->public_id], 'params' => ['price_minor' => 500]])->assertOk();
        $this->assertSame([500, null], [$priced->refresh()->price_minor, $priced->sale_price_minor], 'a sale price no longer below the price is removed');
        $this->postJson('/api/v1/products/bulk', ['action' => 'adjust_price', 'products' => [$priced->public_id], 'params' => ['percent' => 'x']])->assertStatus(422);

        // Unarchiving counts again: priced, unpriced and this one fill the limit (3).
        $extra = $this->product(['status' => 'draft'], $store);
        app(\App\Domain\Packages\Services\EntitlementService::class)->recordUsage('max_products', 3); // factory products skip the counter
        $result = $this->postJson('/api/v1/products/bulk', ['action' => 'unpublish', 'products' => [$archived->public_id]])->assertOk()->json('data');
        $this->assertSame(0, $result['affected']);
        $this->assertStringContainsString('limit', $result['skipped'][0]['reason']);

        $this->postJson('/api/v1/products/bulk', ['action' => 'add_tags', 'products' => [$priced->public_id, $extra->public_id], 'params' => ['tags' => ['Sale']]])->assertOk()->assertJsonPath('data.affected', 2);
        $this->postJson('/api/v1/products/bulk', ['action' => 'delete', 'products' => [$extra->public_id]])->assertOk()->assertJsonPath('data.affected', 1);
        $this->assertSoftDeleted('products', ['id' => $extra->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.bulk_publish']);

        // Permissions are checked per product: a viewer changes nothing.
        $viewer = $this->staffWith(['products.view']);
        $this->actingAs($viewer)->postJson('/api/v1/products/bulk', ['action' => 'archive', 'products' => [$this->product()->public_id]])->assertOk()->assertJsonPath('data.affected', 0);
    }

    public function test_a_promotion_can_aim_at_a_collection(): void
    {
        $in = $this->product(['name' => 'In', 'price_minor' => 10000, 'currency' => 'PKR']);
        $out = $this->product(['name' => 'Out', 'price_minor' => 10000, 'currency' => 'PKR']);
        $collection = $this->actingAs($this->owner)->postJson('/api/v1/collections', ['name' => 'Deals', 'type' => 'manual'])->json('data.id');
        $this->putJson("/api/v1/collections/{$collection}/products", ['products' => [$in->public_id]])->assertOk();
        $collectionId = Collection::query()->where('public_id', $collection)->value('id');

        $this->postJson('/api/v1/promotions', ['name' => 'Deals 20%', 'type' => 'percentage', 'percentage_value' => 20, 'target_scope' => 'collection', 'target_ids' => [$collectionId], 'status' => 'active'])
            ->assertCreated();
        $foreign = Collection::query()->withoutTenantScope()->create(['store_id' => $this->storeWithOwner()[0]->id, 'name' => 'B', 'slug' => 'b']);
        app(TenantContext::class)->resolveToStore($this->store->id);
        $this->actingAs($this->owner)->postJson('/api/v1/promotions', ['name' => 'Bad', 'type' => 'percentage', 'percentage_value' => 5, 'target_scope' => 'collection', 'target_ids' => [$foreign->id]])
            ->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $headers = ['X-Store-Slug' => $this->store->slug];
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $in->id, 'quantity' => 1], $headers)->headers->get('X-Guest-Cart-Token');
        $this->postJson('/api/v1/cart/items', ['product_id' => $out->id, 'quantity' => 1], [...$headers, 'X-Guest-Cart-Token' => $token]);
        $this->getJson('/api/v1/cart', [...$headers, 'X-Guest-Cart-Token' => $token])->assertOk()
            ->assertJsonPath('data.promotion.discount_amount_minor', 2000);
        $this->assertSame(1, Promotion::query()->count());
    }
}
