<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Attribute;
use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\PlatformStarterTemplate;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\AttributeManager;
use App\Domain\Catalog\Services\CategoryAttributes;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Settings\Models\ContentTranslation;
use App\Domain\Settings\Services\TranslationService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Database\Seeders\PackageSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Phase B45 follow-up — Module 07 §38, §101, §106; Module 03 §52: Urdu
 * text from templates, templates managed by Umar Techy staff, and saving a
 * store's structure as a template to start other stores from (the
 * controlled form of store cloning).
 */
final class PlatformStarterTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(PackageSeeder::class);
        \Illuminate\Support\Facades\Queue::fake();
        $this->admin = User::factory()->create(['platform_role' => 'super_admin']);
    }

    /** @return array{0: Store, 1: User} */
    private function storeOn(string $package, string $category = 'fashion'): array
    {
        $store = Store::factory()->create(['status' => 'active', 'business_category' => $category]);
        Subscription::factory()->for($store)->for(Package::query()->where('code', $package)->firstOrFail())->create(['status' => SubscriptionStatus::Active]);
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);
        app(TenantContext::class)->resolveToStore($store->id);
        Cache::flush();

        return [$store, $owner];
    }

    private function urdu(string $type, int $id, string $field = 'name'): ?string
    {
        return ContentTranslation::query()->withoutTenantScope()->where(['translatable_type' => $type, 'translatable_id' => $id, 'locale' => 'ur', 'field' => $field])->value('value');
    }

    private function signOut(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_new_items_get_their_urdu_text_and_the_stores_own_items_are_left_alone(): void
    {
        [, $owner] = $this->storeOn('premium');
        $women = Category::query()->create(['name' => 'Women', 'slug' => 'women', 'status' => 'active', 'visibility' => 'public']);

        $summary = $this->actingAs($owner)->postJson('/api/v1/starter-templates/fashion/apply')->assertOk()->json('data');
        $this->assertGreaterThan(30, $summary['translations_added']);

        $men = Category::query()->where('name', 'Men')->whereNull('parent_id')->firstOrFail();
        $this->assertSame('مرد', $this->urdu('category', $men->id));
        $this->assertSame('مردوں کے لیے شلوار قمیض، کرتے، شرٹس اور واسکٹ۔', $this->urdu('category', $men->id, 'description'));
        $this->assertSame('شلوار قمیض', $this->urdu('category', Category::query()->where('name', 'Shalwar kameez')->value('id')));
        $colour = Attribute::query()->where('key', 'color')->firstOrFail();
        $this->assertSame('رنگ', $this->urdu('attribute', $colour->id));
        $this->assertSame('سیاہ', $this->urdu('attribute_value', $colour->values->firstWhere('value', 'Black')->id, 'value'));
        $this->assertNull($this->urdu('attribute_value', Attribute::query()->where('key', 'size')->firstOrFail()->values->firstWhere('value', 'XL')->id, 'value'), 'sizes read the same');
        $this->assertNull($this->urdu('category', $women->id), 'the store’s own category is not touched');
    }

    public function test_staff_switch_a_built_in_template_off_or_keep_it_for_staff_only(): void
    {
        [$store, $owner] = $this->storeOn('premium');
        $this->signOut();
        $this->actingAs($owner)->getJson('/api/v1/super-admin/starter-templates')->assertForbidden();
        $this->signOut();

        $list = $this->actingAs($this->admin)->getJson('/api/v1/super-admin/starter-templates')->assertOk()->json('data');
        $this->assertCount(14, $list);
        $this->assertSame(['built_in', true, true], [$list[0]['source'], $list[0]['is_active'], $list[0]['offered_to_stores']]);
        $this->actingAs($this->admin)->putJson('/api/v1/super-admin/starter-templates/fashion', ['name' => 'Mine'])->assertStatus(422);
        $this->actingAs($this->admin)->putJson('/api/v1/super-admin/starter-templates/fashion', ['is_active' => false])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'starter_template.updated']);

        $this->signOut();
        app(TenantContext::class)->resolveToStore($store->id);
        $index = $this->actingAs($owner)->getJson('/api/v1/starter-templates')->assertOk();
        $this->assertNull($index->json('recommended'));
        $this->assertNotContains('fashion', array_column($index->json('data'), 'key'));
        $this->actingAs($owner)->getJson('/api/v1/starter-templates/fashion')->assertNotFound();
        $this->actingAs($owner)->postJson('/api/v1/starter-templates/fashion/apply')->assertNotFound();
        $this->assertSame(0, Category::query()->count());

        // On again, but for staff only: owners still cannot pick it; staff creating a store can.
        $this->signOut();
        $this->actingAs($this->admin)->putJson('/api/v1/super-admin/starter-templates/fashion', ['is_active' => true, 'offered_to_stores' => false])->assertOk();
        $this->signOut();
        $this->actingAs($owner)->getJson('/api/v1/starter-templates/fashion')->assertNotFound();
        $this->signOut();
        $id = $this->actingAs($this->admin)->postJson('/api/v1/super-admin/stores', [
            'store_name' => 'Team Boutique', 'business_category' => 'fashion', 'package_code' => 'basic', 'owner_email' => 'team@example.com',
            'starter_template' => true, 'starter_template_key' => 'fashion',
        ])->assertCreated()->json('data.id');
        $this->assertTrue(Category::query()->withoutTenantScope()->where('store_id', $id)->where('name', 'Women')->exists());
        $this->actingAs($this->admin)->postJson('/api/v1/super-admin/stores', [
            'store_name' => 'Other', 'business_category' => 'fashion', 'package_code' => 'basic', 'owner_email' => 'x@example.com',
            'starter_template' => true, 'starter_template_key' => 'spaceships',
        ])->assertStatus(422)->assertJsonValidationErrors('starter_template_key');
    }

    public function test_staff_save_a_stores_structure_and_start_new_stores_from_it(): void
    {
        // A bakery the team set up: a three-level tree, an attribute with Urdu, a colour without a code, brands and a product.
        [$bakery] = $this->storeOn('premium', 'food');
        $cakes = Category::query()->create(['name' => 'Cakes', 'slug' => 'cakes', 'description' => 'Fresh cakes daily.', 'status' => 'active', 'visibility' => 'public']);
        $birthday = Category::query()->create(['name' => 'Birthday', 'slug' => 'birthday', 'parent_id' => $cakes->id, 'status' => 'active', 'visibility' => 'public']);
        Category::query()->create(['name' => 'Kids birthday', 'slug' => 'kids-birthday', 'parent_id' => $birthday->id, 'status' => 'active', 'visibility' => 'public']);
        Category::query()->create(['name' => 'Old stock', 'slug' => 'old', 'status' => 'archived', 'visibility' => 'hidden']);
        $flavour = Attribute::query()->create(['name' => 'Flavour', 'key' => 'flavour', 'type' => 'select']);
        app(AttributeManager::class)->syncValues($flavour, ['Chocolate', 'Mango']);
        $icing = Attribute::query()->create(['name' => 'Icing', 'key' => 'icing', 'type' => 'color']);
        app(AttributeManager::class)->syncValues($icing, [['value' => 'Rose', 'color_code' => null]]);
        Attribute::query()->create(['name' => 'Retired', 'key' => 'retired', 'type' => 'text', 'is_active' => false]);
        app(CategoryAttributes::class)->set($cakes, [['attribute_id' => $flavour->id, 'is_filter' => true, 'is_required' => true]], $this->admin);
        $mango = AttributeValue::query()->where('attribute_id', $flavour->id)->where('value', 'Mango')->firstOrFail();
        app(TranslationService::class)->save('attribute_value', $mango->id, 'ur', ['value' => 'آم'], null);
        app(TranslationService::class)->save('category', $cakes->id, 'ur', ['name' => 'کیک', 'description' => 'روز تازہ کیک۔'], null);
        Brand::query()->create(['name' => 'Hilal', 'slug' => 'hilal']);
        Product::factory()->create(['store_id' => $bakery->id, 'name' => 'Secret Recipe Cake']);
        app(TenantContext::class)->resolveToPlatform();

        $saved = $this->actingAs($this->admin)->postJson("/api/v1/super-admin/stores/{$bakery->id}/starter-template", ['name' => 'Lahore bakery', 'business_category' => 'food'])
            ->assertCreated()->json('data');
        $this->assertSame(1, $saved['skipped_deeper_categories'], 'Kids birthday is a third level');
        $this->assertMatchesRegularExpression('/^store_lahore_bakery_[a-z0-9]{4}$/', $saved['key']);
        $row = PlatformStarterTemplate::query()->where('key', $saved['key'])->firstOrFail();
        $json = json_encode($row->definition, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Secret Recipe Cake', $json, 'never products');
        $this->assertStringNotContainsString('Old stock', $json, 'never archived categories');
        $this->assertStringNotContainsString('retired', $json, 'never inactive attributes');
        $this->assertSame(['Hilal'], $row->definition['brands']);
        $this->assertSame([false, true], [$row->offered_to_stores, $row->is_active], 'saved templates are for staff until offered');
        $this->assertDatabaseHas('audit_logs', ['action' => 'starter_template.saved_from_store']);

        // Another store's owner does not see it yet.
        [$other, $otherOwner] = $this->storeOn('basic', 'food');
        $this->actingAs($otherOwner)->getJson("/api/v1/starter-templates/{$saved['key']}")->assertNotFound();
        $this->signOut();

        // Staff start a new store from it: its own ids, the two-level tree, filters, Urdu; no brands, no products.
        $id = $this->actingAs($this->admin)->postJson('/api/v1/super-admin/stores', [
            'store_name' => 'Karachi Bakes', 'business_category' => 'food', 'package_code' => 'basic', 'owner_email' => 'bakes@example.com',
            'starter_template' => true, 'starter_template_key' => $saved['key'],
        ])->assertCreated()->json('data.id');
        $newCakes = Category::query()->withoutTenantScope()->where('store_id', $id)->where('name', 'Cakes')->firstOrFail();
        $this->assertNotSame($cakes->id, $newCakes->id);
        $this->assertSame('Fresh cakes daily.', $newCakes->description);
        $this->assertTrue(Category::query()->withoutTenantScope()->where('store_id', $id)->where('name', 'Birthday')->where('parent_id', $newCakes->id)->exists());
        $this->assertFalse(Category::query()->withoutTenantScope()->where('store_id', $id)->where('name', 'Kids birthday')->exists());
        $this->assertSame('کیک', $this->urdu('category', $newCakes->id));
        $newFlavour = Attribute::query()->withoutTenantScope()->where('store_id', $id)->where('key', 'flavour')->firstOrFail();
        $this->assertSame('آم', $this->urdu('attribute_value', $newFlavour->values->firstWhere('value', 'Mango')->id, 'value'));
        $this->assertNull(Attribute::query()->withoutTenantScope()->where('store_id', $id)->where('key', 'icing')->firstOrFail()->values->first()->color_code);
        $this->assertSame(0, Brand::query()->withoutTenantScope()->where('store_id', $id)->count());
        $this->assertSame(0, Product::query()->withoutTenantScope()->where('store_id', $id)->count());

        $listed = collect($this->actingAs($this->admin)->getJson('/api/v1/super-admin/starter-templates')->json('data'))->firstWhere('key', $saved['key']);
        $this->assertSame([1, $bakery->name, 'store'], [$listed['stores_using'], $listed['source_store']['name'], $listed['source']]);
        $this->assertSame(['Cakes'], array_column($this->actingAs($this->admin)->getJson("/api/v1/super-admin/starter-templates/{$saved['key']}")->json('data.categories'), 'name'));

        // Offered and renamed: store owners can now choose it; deleted: gone, stores keep what it added.
        $this->actingAs($this->admin)->putJson("/api/v1/super-admin/starter-templates/{$saved['key']}", ['offered_to_stores' => true, 'name' => 'Bakery (Lahore style)'])->assertOk();
        $this->signOut();
        app(TenantContext::class)->resolveToStore($other->id);
        $offered = collect($this->actingAs($otherOwner)->getJson('/api/v1/starter-templates')->json('data'))->firstWhere('key', $saved['key']);
        $this->assertSame('Bakery (Lahore style)', $offered['name']);
        $this->signOut();
        $this->actingAs($this->admin)->deleteJson("/api/v1/super-admin/starter-templates/{$saved['key']}")->assertNoContent();
        $this->actingAs($this->admin)->deleteJson('/api/v1/super-admin/starter-templates/fashion')->assertNotFound();
        $this->assertTrue(Category::query()->withoutTenantScope()->where('store_id', $id)->where('name', 'Cakes')->exists());
    }
}
