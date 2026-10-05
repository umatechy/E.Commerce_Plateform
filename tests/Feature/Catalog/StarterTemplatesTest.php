<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Attribute;
use App\Domain\Catalog\Models\AttributeSet;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\CategoryAttribute;
use App\Domain\Catalog\Services\AttributeManager;
use App\Domain\Catalog\Services\CategoryAttributes;
use App\Domain\Catalog\Support\StarterTemplates;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Storefront\Services\StorefrontCatalog;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\BusinessCategories;
use App\Domain\Tenancy\Support\TenantContext;
use App\Domain\Theme\Models\StoreTheme;
use App\Domain\Theme\Support\ThemeCatalog;
use Database\Seeders\PackageSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase B45 — Module 07 §20, §101, §103, §105–106; Module 03 §58; gap
 * G23: starter templates per business category, copied into the store's
 * own data, adding only.
 */
final class StarterTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(PackageSeeder::class);
        \Illuminate\Support\Facades\Queue::fake();
        [$this->store, $this->owner] = $this->storeOn('premium', 'active');
    }

    /** @return array{0: Store, 1: User} */
    private function storeOn(string $package, string $status): array
    {
        $store = Store::factory()->create(['status' => $status, 'business_category' => 'fashion']);
        Subscription::factory()->for($store)->for(Package::query()->where('code', $package)->firstOrFail())->create(['status' => SubscriptionStatus::Active]);
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);
        app(TenantContext::class)->resolveToStore($store->id);
        Cache::flush();

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

    private function category(string $name, ?Category $parent = null): ?Category
    {
        return Category::query()->where('name', $name)->where('parent_id', $parent?->id)->first();
    }

    public function test_every_business_category_has_a_well_formed_template(): void
    {
        $types = [];
        foreach (BusinessCategories::keys() as $business) {
            $key = StarterTemplates::forBusinessCategory($business);
            $this->assertNotNull($key, "{$business} has a template");
            $t = StarterTemplates::get($key);
            $keys = array_column($t['attributes'], 'key');
            $this->assertSame($keys, array_unique($keys), "{$key}: attribute keys are unique");
            foreach ($t['attributes'] as $a) {
                // One key means one thing in every template, so two templates in one store never mix values.
                $this->assertSame($types[$a['key']] ??= $a['type'], $a['type'], "{$a['key']} has one type across templates");
                $this->assertLessThanOrEqual(AttributeManager::MAX_VALUES, count($a['values'] ?? []));
                foreach ($a['values'] ?? [] as $v) {
                    $this->assertSame($a['type'] === 'color', is_array($v), "{$key}.{$a['key']}: colour values carry a code, others do not");
                    if (is_array($v)) {
                        $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $v[1]);
                    }
                }
            }
            $names = array_map(fn ($c) => mb_strtolower($c['name']), $t['categories']);
            $this->assertSame($names, array_unique($names), "{$key}: category names are unique");
            foreach ($t['categories'] as $c) {
                $this->assertNotEmpty($c['description'] ?? '', "{$key}: {$c['name']} has an SEO description");
                $this->assertLessThanOrEqual(CategoryAttributes::MAX_PER_CATEGORY, count($c['attributes'] ?? []));
                foreach ($c['attributes'] ?? [] as $attr => $spec) {
                    $this->assertContains($attr, $keys, "{$key}: {$c['name']} uses a defined attribute");
                    $this->assertEmpty(array_diff(StarterTemplates::flags($spec), ['filter', 'required']));
                }
            }
            foreach ($t['themes'] as $theme) {
                $this->assertTrue(ThemeCatalog::has($theme), "{$key}: theme {$theme} exists");
            }
            $this->assertContains($t['default_sort'], StorefrontCatalog::SORTS);
        }
    }

    public function test_the_owner_previews_and_applies_a_template_into_the_stores_own_catalogue(): void
    {
        $index = $this->actingAs($this->owner)->getJson('/api/v1/starter-templates')->assertOk();
        $this->assertSame('fashion', $index->json('recommended'));
        $this->assertCount(count(BusinessCategories::keys()), $index->json('data'));

        $preview = $this->actingAs($this->owner)->getJson('/api/v1/starter-templates/fashion')->assertOk();
        $this->assertSame(['Women', false], [$preview->json('data.categories.0.name'), $preview->json('data.categories.0.exists')]);
        $this->assertSame(['boutique', true], [$preview->json('data.theme.key'), $preview->json('data.theme.preferred')]);
        $this->actingAs($this->owner)->getJson('/api/v1/starter-templates/spaceships')->assertNotFound();

        $summary = $this->actingAs($this->owner)->postJson('/api/v1/starter-templates/fashion/apply', ['theme' => true, 'default_sort' => true])->assertOk()->json('data');
        $this->assertSame([17, 7, 0], [$summary['categories_added'], $summary['attributes_added'], $summary['brands_added']]);

        $women = $this->category('Women');
        $this->assertSame(['active', 'public', $this->store->id], [$women->status->value, $women->visibility, $women->store_id]);
        $this->assertNotNull($this->category('Kurtas & kurtis', $women));
        $size = Attribute::query()->where('key', 'size')->firstOrFail();
        $this->assertSame(['XS', 'S', 'M', 'L', 'XL', 'XXL'], $size->values->pluck('value')->all());
        $this->assertSame('#1F2A44', Attribute::query()->where('key', 'color')->firstOrFail()->values->firstWhere('value', 'Navy')->color_code);
        $flags = CategoryAttribute::query()->where('category_id', $women->id)->with('attribute')->orderBy('position')->get()
            ->mapWithKeys(fn ($ca) => [$ca->attribute->key => $ca->is_filter])->all();
        $this->assertSame(['size' => true, 'color' => true, 'fabric' => true, 'stitching' => true, 'pieces' => true, 'care' => false], $flags);
        $this->assertSame(7, AttributeSet::query()->where('name', 'Clothing & fashion')->firstOrFail()->attributes()->count());

        // A live store: the theme waits in the draft for the owner; the default order is set.
        $theme = StoreTheme::query()->where('store_id', $this->store->id)->firstOrFail();
        $this->assertSame(['boutique', 'default'], [$theme->draft_config['theme'], $theme->published_config['theme']]);
        $this->assertSame(['key' => 'boutique', 'name' => 'Boutique', 'published' => false], $summary['theme']);
        $this->assertSame('newest', app(\App\Domain\Settings\Services\ConfigService::class)->get('catalog.default_sort'));

        $this->assertSame('fashion', $this->actingAs($this->owner)->getJson('/api/v1/starter-templates')->json('history.0.key'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.starter_template_applied', 'store_id' => $this->store->id]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'catalog.starter_template_applied', 'store_id' => $this->store->id]);
    }

    public function test_applying_only_adds_and_never_changes_what_the_store_already_has(): void
    {
        // The owner's own structure before the template, and another store with the same names.
        $women = Category::query()->create(['name' => 'women', 'slug' => 'mine', 'description' => 'Mine', 'status' => 'draft', 'visibility' => 'hidden']);
        $size = Attribute::query()->create(['name' => 'Size', 'key' => 'size', 'type' => 'select']);
        app(AttributeManager::class)->syncValues($size, ['Free size']);
        $fit = Attribute::query()->create(['name' => 'Fit notes', 'key' => 'fit', 'type' => 'text']);
        app(CategoryAttributes::class)->set($women, [['attribute_id' => $size->id, 'is_filter' => false, 'is_required' => true]], $this->owner);
        app(\App\Domain\Settings\Services\ConfigService::class)->set('catalog.default_sort', 'price_asc', \App\Domain\Settings\Models\SettingScope::Store, $this->owner->id);
        [$other] = $this->storeOn('basic', 'active');
        Category::query()->create(['name' => 'Men', 'slug' => 'men', 'status' => 'active', 'visibility' => 'public']);
        app(TenantContext::class)->resolveToStore($this->store->id);

        $first = $this->actingAs($this->owner)->postJson('/api/v1/starter-templates/fashion/apply', ['default_sort' => true])->assertOk()->json('data');
        $this->assertSame(['fit'], $first['attributes_kept']);
        $this->assertSame(1, $first['categories_kept']);
        $this->assertNull($first['default_sort'], 'the store chose its own order');

        $women->refresh();
        $this->assertSame(['women', 'Mine', 'draft', 'hidden'], [$women->name, $women->description, $women->status->value, $women->visibility]);
        $this->assertSame(['Free size', 'XS', 'S', 'M', 'L', 'XL', 'XXL'], $size->fresh()->values->pluck('value')->all());
        $this->assertSame('text', $fit->fresh()->type->value);
        $own = CategoryAttribute::query()->where('category_id', $women->id)->orderBy('position')->get();
        $this->assertSame([$size->id, false, true], [$own[0]->attribute_id, $own[0]->is_filter, $own[0]->is_required], 'the owner’s flags stay');
        $menFit = CategoryAttribute::query()->where('category_id', $this->category('Men')?->id)->where('attribute_id', $fit->id)->firstOrFail();
        $this->assertFalse($menFit->is_filter, 'the store’s own text attribute is used, never as a filter');
        $this->assertSame('price_asc', app(\App\Domain\Settings\Services\ConfigService::class)->get('catalog.default_sort'));

        $counts = [Category::query()->count(), DB::table('attribute_values')->count(), CategoryAttribute::query()->count()];
        $again = $this->actingAs($this->owner)->postJson('/api/v1/starter-templates/fashion/apply', [])->assertOk()->json('data');
        $this->assertSame([0, 0, 0], [$again['categories_added'], $again['values_added'], $again['category_attributes_added']]);
        $this->assertSame($counts, [Category::query()->count(), DB::table('attribute_values')->count(), CategoryAttribute::query()->count()]);

        // The other store's "Men" was not taken as this store's; nothing was written to it.
        $this->assertSame(1, Category::query()->withoutTenantScope()->where('store_id', $other->id)->count());
        $this->assertNotNull($this->category('Men'));
    }

    public function test_brands_are_added_only_when_asked_and_each_part_needs_its_permission(): void
    {
        app(TenantContext::class)->resolveToStore($this->store->id);
        $viewer = $this->staffWith(['products.view']);
        $categoriesOnly = $this->staffWith(['categories.manage']);
        $catalogue = $this->staffWith(['categories.manage', 'attributes.manage']);

        $this->actingAs($viewer)->getJson('/api/v1/starter-templates')->assertForbidden();
        $this->actingAs($categoriesOnly)->getJson('/api/v1/starter-templates')->assertOk();
        $this->actingAs($categoriesOnly)->postJson('/api/v1/starter-templates/electronics/apply')->assertForbidden();
        $this->actingAs($catalogue)->postJson('/api/v1/starter-templates/electronics/apply', ['brands' => true])->assertForbidden();
        $this->actingAs($catalogue)->postJson('/api/v1/starter-templates/electronics/apply', ['theme' => true])->assertForbidden();
        $this->assertSame(0, Category::query()->count(), 'a refused request writes nothing');

        $this->actingAs($catalogue)->postJson('/api/v1/starter-templates/electronics/apply')->assertOk()->assertJsonPath('data.brands_added', 0);
        $this->assertSame(0, Brand::query()->count());

        Brand::query()->create(['name' => 'samsung', 'slug' => 'samsung']);
        $this->actingAs($this->owner)->postJson('/api/v1/starter-templates/electronics/apply', ['brands' => true])->assertOk()->assertJsonPath('data.brands_added', 7);
        $this->assertSame(8, Brand::query()->count());
        $this->assertTrue($this->actingAs($this->owner)->getJson('/api/v1/starter-templates/electronics')->json('data.brands.0.exists'));
        // "Condition" is required on phones, as the template says.
        $phones = $this->category('Mobile phones');
        $this->assertTrue((bool) CategoryAttribute::query()->where('category_id', $phones->id)->where('attribute_id', Attribute::query()->where('key', 'condition')->value('id'))->value('is_required'));
    }

    public function test_sign_up_and_staff_created_stores_can_start_from_their_template(): void
    {
        $this->app['auth']->forgetGuards();
        $password = 'correct-horse-battery-9';
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Sana', 'email' => 'sana@example.com', 'password' => $password, 'password_confirmation' => $password,
            'store_name' => 'Sana Mobiles', 'business_category' => 'electronics', 'starter_template' => true,
        ])->assertCreated();
        $sana = Store::query()->where('name', 'Sana Mobiles')->firstOrFail();
        $this->assertTrue(Category::query()->withoutTenantScope()->where('store_id', $sana->id)->where('name', 'Mobile phones')->exists());
        $this->assertSame(0, Brand::query()->withoutTenantScope()->where('store_id', $sana->id)->count(), 'brands stay the owner’s choice');
        // The trial package (Basic) has neither Modern nor Bold: the store stays on Classic.
        $this->assertSame('default', StoreTheme::query()->withoutTenantScope()->where('store_id', $sana->id)->firstOrFail()->published_config['theme']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'store.provisioned', 'store_id' => $sana->id]);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ali', 'email' => 'ali@example.com', 'password' => $password, 'password_confirmation' => $password,
            'store_name' => 'Ali Empty', 'business_category' => 'electronics',
        ])->assertCreated();
        $empty = Store::query()->where('name', 'Ali Empty')->firstOrFail();
        $this->assertSame(0, Category::query()->withoutTenantScope()->where('store_id', $empty->id)->count(), 'start empty');

        $this->app['auth']->forgetGuards();
        $admin = User::factory()->create(['platform_role' => 'super_admin']);
        $id = $this->actingAs($admin)->postJson('/api/v1/super-admin/stores', [
            'store_name' => 'Noor Boutique', 'business_category' => 'fashion', 'package_code' => 'premium', 'owner_email' => 'noor@example.com', 'starter_template' => true,
        ])->assertCreated()->json('data.id');
        // Not live yet: the suggested theme the package includes is published at once.
        $this->assertSame('boutique', StoreTheme::query()->withoutTenantScope()->where('store_id', $id)->firstOrFail()->published_config['theme']);
        $this->assertSame(4, Category::query()->withoutTenantScope()->where('store_id', $id)->whereNull('parent_id')->count());
        $this->actingAs($admin)->getJson("/api/v1/super-admin/stores/{$id}")->assertOk()->assertJsonPath('data.starter_templates.0.key', 'fashion');
    }
}
