<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Attribute;
use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase B41 — Module 07 §18–19, §27–49, §75–77: attributes and values,
 * attribute sets, category attributes (required / filter), product
 * specifications, and faceted category filters on the storefront.
 */
final class AttributesAndFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private Category $phones;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->store, $this->owner] = $this->storeWithOwner();
        $this->phones = Category::factory()->for($this->store)->create(['name' => 'Phones', 'slug' => 'phones', 'status' => 'active', 'visibility' => 'public']);
    }

    /** @return array{0: Store, 1: User} */
    private function storeWithOwner(): array
    {
        $store = Store::factory()->create(['status' => 'active']);
        $package = Package::factory()->create();
        $package->entitlements()->create(['key' => 'products.basic', 'type' => EntitlementType::Feature, 'boolean_value' => true]);
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

    /** @param list<string>|array<int, array<string, mixed>> $values */
    private function attribute(string $name, string $key, string $type, array $values = [], ?string $unit = null): Attribute
    {
        $id = $this->actingAs($this->owner)->postJson('/api/v1/attributes', ['name' => $name, 'key' => $key, 'type' => $type, 'values' => $values, 'unit' => $unit])->assertCreated()->json('data.id');

        return Attribute::query()->findOrFail($id);
    }

    private function valueId(Attribute $attribute, string $value): int
    {
        return (int) AttributeValue::query()->where('attribute_id', $attribute->id)->where('value', $value)->value('id');
    }

    private function phone(string $name, array $attributes = []): Product
    {
        return Product::factory()->create(['store_id' => $this->store->id, 'name' => $name, 'status' => 'active', 'visibility' => 'public', 'price_minor' => 100000, 'primary_category_id' => $this->phones->id, ...$attributes]);
    }

    /** @param array<string, string> $attr */
    private function listing(array $attr = [], string $category = 'phones'): array
    {
        $this->app['auth']->forgetGuards();
        $query = http_build_query(['category' => $category, 'attr' => $attr]);

        return $this->getJson("/api/v1/storefront/products?{$query}", ['X-Store-Slug' => $this->store->slug])->assertOk()->json('data');
    }

    public function test_attribute_values_are_edited_reordered_and_kept_inactive_while_in_use(): void
    {
        $color = $this->attribute('Colour', 'colour', 'color', ['Black', 'White']);
        $this->assertSame(['black', 'white'], $color->values()->pluck('slug')->all());
        $tone = $this->attribute('Tone', 'tone', 'color', [['value' => 'Red', 'color_code' => '#ff0000']]);
        $this->assertSame('#FF0000', $tone->values()->value('color_code'), 'colour codes can be given when the attribute is created');

        $black = $this->valueId($color, 'Black');
        $white = $this->valueId($color, 'White');
        $phone = $this->phone('P1');
        $this->putJson("/api/v1/products/{$phone->public_id}/specifications", ['specifications' => [['attribute_id' => $color->id, 'value' => $white]]])->assertOk();

        $this->putJson("/api/v1/attributes/{$color->id}", ['group' => 'Looks', 'values' => [
            ['id' => $black, 'value' => 'Jet Black', 'color_code' => '#111111'],
            ['value' => 'Blue', 'color_code' => '#0000ff'],
        ]])->assertOk()
            ->assertJsonPath('data.group', 'Looks')
            ->assertJsonPath('data.options.0.value', 'Jet Black')
            ->assertJsonPath('data.options.1.color_code', '#0000FF')
            ->assertJsonPath('data.options.2.value', 'White')
            ->assertJsonPath('data.options.2.is_active', false); // in use: deactivated, not deleted

        $this->putJson("/api/v1/attributes/{$color->id}", ['values' => [['value' => 'Red'], ['value' => 'red']]])->assertStatus(422);
        $this->putJson("/api/v1/attributes/{$color->id}", ['values' => [['value' => 'Red', 'color_code' => 'red']]])->assertStatus(422);
        $this->putJson("/api/v1/attributes/{$color->id}", ['type' => 'text'])->assertStatus(422); // products use it

        // A deactivated value cannot be chosen again.
        $this->putJson("/api/v1/products/{$phone->public_id}/specifications", ['specifications' => [['attribute_id' => $color->id, 'value' => $white]]])->assertStatus(422);
    }

    public function test_specifications_are_typed_and_required_ones_of_the_category_are_enforced(): void
    {
        $ram = $this->attribute('RAM', 'ram', 'select', ['8 GB', '16 GB']);
        $screen = $this->attribute('Screen', 'screen', 'numeric', [], 'inch');
        $waterproof = $this->attribute('Waterproof', 'waterproof', 'boolean');
        $features = $this->attribute('Features', 'features', 'multi_select', ['NFC', '5G', 'Wireless charging']);
        $this->putJson("/api/v1/categories/{$this->phones->id}/attributes", ['attributes' => [
            ['attribute_id' => $ram->id, 'is_required' => true, 'is_filter' => true],
            ['attribute_id' => $screen->id, 'is_filter' => true],
        ]])->assertOk()->assertJsonCount(2, 'data.attributes');
        $phone = $this->phone('Pixel', ['slug' => 'pixel']);

        $this->putJson("/api/v1/products/{$phone->public_id}/specifications", ['specifications' => [['attribute_id' => $screen->id, 'value' => 6.1]]])
            ->assertStatus(422)->assertJsonPath('errors.specifications.0', "Required for this product's category: RAM.");
        $this->putJson("/api/v1/products/{$phone->public_id}/specifications", ['specifications' => [['attribute_id' => $ram->id, 'value' => 'lots']]])->assertStatus(422);
        $this->putJson("/api/v1/products/{$phone->public_id}/specifications", ['specifications' => [
            ['attribute_id' => $ram->id, 'value' => $this->valueId($ram, '16 GB')],
            ['attribute_id' => $waterproof->id, 'value' => 'maybe'],
        ]])->assertStatus(422);

        $this->putJson("/api/v1/products/{$phone->public_id}/specifications", ['specifications' => [
            ['attribute_id' => $ram->id, 'value' => $this->valueId($ram, '16 GB')],
            ['attribute_id' => $screen->id, 'value' => 6.1],
            ['attribute_id' => $waterproof->id, 'value' => true],
            ['attribute_id' => $features->id, 'value' => [$this->valueId($features, 'NFC'), $this->valueId($features, '5G')]],
        ]])->assertOk()->assertJsonPath('data.suggested.0.is_required', true)->assertJsonCount(4, 'data.values');

        // The product page shows them, in the attributes' order, with the unit.
        $this->app['auth']->forgetGuards();
        $specs = $this->getJson('/api/v1/storefront/products/pixel', ['X-Store-Slug' => $this->store->slug])->assertOk()->json('data.product.specifications');
        $this->assertEqualsCanonicalizing(
            ['RAM: 16 GB', 'Screen: 6.1 inch', 'Waterproof: yes', 'Features: NFC, 5G'],
            array_map(fn ($s) => "{$s['name']}: {$s['value']}", $specs),
        );
    }

    public function test_category_filters_narrow_the_listing_and_count_each_value_with_the_other_filters(): void
    {
        $ram = $this->attribute('RAM', 'ram', 'select', ['8 GB', '16 GB', '32 GB']);
        $screen = $this->attribute('Screen', 'screen', 'numeric', [], 'inch');
        $notes = $this->attribute('Notes', 'notes', 'text');
        $this->actingAs($this->owner)->putJson("/api/v1/categories/{$this->phones->id}/attributes", ['attributes' => [
            ['attribute_id' => $ram->id, 'is_filter' => true], ['attribute_id' => $screen->id, 'is_filter' => true],
        ]])->assertOk();
        $this->putJson("/api/v1/categories/{$this->phones->id}/attributes", ['attributes' => [['attribute_id' => $notes->id, 'is_filter' => true]]])->assertStatus(422);

        $spec = function (Product $p, string $ramValue, float $inches) use ($ram, $screen) {
            $this->actingAs($this->owner)->putJson("/api/v1/products/{$p->public_id}/specifications", ['specifications' => [
                ['attribute_id' => $ram->id, 'value' => $this->valueId($ram, $ramValue)], ['attribute_id' => $screen->id, 'value' => $inches],
            ]])->assertOk();
        };
        $spec($this->phone('A'), '8 GB', 6.1);
        $spec($this->phone('B'), '8 GB', 6.7);
        $spec($this->phone('C'), '16 GB', 6.7);

        $all = $this->listing();
        $this->assertSame(3, $all['pagination']['total']);
        $ramFilter = collect($all['filters'])->firstWhere('key', 'ram');
        $this->assertSame([['8-gb', 2], ['16-gb', 1]], array_map(fn ($o) => [$o['slug'], $o['count']], $ramFilter['options']), '32 GB has no products: not offered');
        $this->assertSame(['min' => 6.1, 'max' => 6.7], collect($all['filters'])->firstWhere('key', 'screen')['range']);

        $narrow = $this->listing(['ram' => '8-gb', 'screen' => '6.5-']);
        $this->assertSame(['B'], array_column($narrow['products'], 'name'));
        // Counts of RAM ignore the RAM choice but keep the screen range.
        $this->assertSame([['8-gb', 1, true], ['16-gb', 1, false]], array_map(fn ($o) => [$o['slug'], $o['count'], $o['selected']], collect($narrow['filters'])->firstWhere('key', 'ram')['options']));

        // Unknown attributes and values in the address are ignored, never used.
        $this->assertSame(3, $this->listing(['ram' => 'drop-table', 'cost_price_minor' => '1', 'screen' => 'x-y'])['pagination']['total']);
        // A child category follows its parent's filters.
        $child = Category::factory()->for($this->store)->create(['name' => 'Android', 'slug' => 'android', 'parent_id' => $this->phones->id, 'status' => 'active', 'visibility' => 'public']);
        $spec($this->phone('D', ['primary_category_id' => $child->id]), '32 GB', 6.0);
        $android = $this->listing([], 'android');
        $this->assertSame(['ram', 'screen'], array_column($android['filters'], 'key'));
        $this->assertSame([['32-gb', 1]], array_map(fn ($o) => [$o['slug'], $o['count']], $android['filters'][0]['options']));

        // The server-rendered category page carries the filters and is not indexed when filtered.
        $this->withoutVite()->get("/shop/{$this->store->slug}/categories/phones?attr[ram]=16-gb")->assertOk()
            ->assertInertia(fn ($page) => $page->where('listing.pagination.total', 1)->where('seo.robots', 'noindex, follow'));
    }

    public function test_attribute_sets_apply_to_a_category_and_stay_in_their_store(): void
    {
        $cpu = $this->attribute('Processor', 'cpu', 'select', ['i5', 'i7']);
        $ram = $this->attribute('RAM', 'ram', 'select', ['8 GB']);
        $set = $this->postJson('/api/v1/attribute-sets', ['name' => 'Laptops', 'attributes' => [$cpu->id, $ram->id]])->assertCreated()->json('data');
        $this->postJson('/api/v1/attribute-sets', ['name' => 'Laptops', 'attributes' => []])->assertStatus(422);

        $this->putJson("/api/v1/categories/{$this->phones->id}/attributes", ['attributes' => [], 'apply_set' => $set['id']])->assertOk()
            ->assertJsonPath('data.attributes.0.attribute_id', $cpu->id)->assertJsonPath('data.attributes.1.is_filter', true);

        // Another store: cannot use these attributes, sees no sets.
        [$other, $otherOwner] = $this->storeWithOwner();
        $otherCategory = Category::factory()->for($other)->create();
        $this->actingAs($otherOwner)->putJson("/api/v1/categories/{$otherCategory->id}/attributes", ['attributes' => [['attribute_id' => $cpu->id]]])->assertStatus(422);
        $this->getJson('/api/v1/attribute-sets')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson("/api/v1/attributes/{$cpu->id}", ['name' => 'Mine'])->assertNotFound();

        // Staff without attributes.manage cannot change attributes or sets; product editors manage specifications only.
        app(TenantContext::class)->resolveToStore($this->store->id);
        $editor = $this->staffWith(['products.view', 'products.update']);
        $this->actingAs($editor)->putJson("/api/v1/attributes/{$cpu->id}", ['name' => 'X'])->assertForbidden();
        $this->postJson('/api/v1/attribute-sets', ['name' => 'Y', 'attributes' => []])->assertForbidden();
        $this->putJson("/api/v1/categories/{$this->phones->id}/attributes", ['attributes' => []])->assertForbidden();
        $phone = $this->phone('E');
        $this->putJson("/api/v1/products/{$phone->public_id}/specifications", ['specifications' => [['attribute_id' => $cpu->id, 'value' => $this->valueId($cpu, 'i7')]]])->assertOk();
    }
}
