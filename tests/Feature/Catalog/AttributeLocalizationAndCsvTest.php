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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase B42 — Module 07 §38 (attribute names and values in other storefront
 * languages) and Module 06 §48/§51 (specifications in the CSV import/export).
 */
final class AttributeLocalizationAndCsvTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private Category $phones;

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
        $this->phones = Category::factory()->for($this->store)->create(['name' => 'Phones', 'slug' => 'phones', 'status' => 'active', 'visibility' => 'public']);
    }

    private function attribute(string $name, string $key, string $type, array $values = []): Attribute
    {
        $id = $this->actingAs($this->owner)->postJson('/api/v1/attributes', ['name' => $name, 'key' => $key, 'type' => $type, 'values' => $values])->assertCreated()->json('data.id');

        return Attribute::query()->findOrFail($id);
    }

    private function valueId(Attribute $attribute, string $value): int
    {
        return (int) AttributeValue::query()->where('attribute_id', $attribute->id)->where('value', $value)->value('id');
    }

    private function offerUrdu(): void
    {
        DB::table('store_settings')->updateOrInsert(['store_id' => $this->store->id, 'key' => 'store.languages'], ['value' => json_encode([['en', 'ur']]), 'created_at' => now(), 'updated_at' => now()]);
        Cache::flush();
    }

    private function csv(array $rows): UploadedFile
    {
        $handle = fopen('php://temp', 'w+b');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);

        return UploadedFile::fake()->createWithContent('products.csv', (string) stream_get_contents($handle));
    }

    public function test_attribute_names_and_values_are_translated_together_and_shown_in_the_visitors_language(): void
    {
        $this->offerUrdu();
        $colour = $this->attribute('Colour', 'colour', 'color', ['Black', 'White']);
        $black = $this->valueId($colour, 'Black');
        $this->putJson("/api/v1/categories/{$this->phones->id}/attributes", ['attributes' => [['attribute_id' => $colour->id, 'is_filter' => true]]])->assertOk();
        $phone = Product::factory()->create(['store_id' => $this->store->id, 'name' => 'Phone', 'slug' => 'phone', 'status' => 'active', 'visibility' => 'public', 'primary_category_id' => $this->phones->id]);
        $this->putJson("/api/v1/products/{$phone->public_id}/specifications", ['specifications' => [['attribute_id' => $colour->id, 'value' => $black]]])->assertOk();

        $this->getJson("/api/v1/attributes/{$colour->id}/translations")->assertOk()
            ->assertJsonPath('data.languages.0.code', 'ur')->assertJsonPath('data.original.values.0.value', 'Black');
        $this->putJson("/api/v1/attributes/{$colour->id}/translations", ['locale' => 'ur', 'name' => 'رنگ', 'values' => [$black => 'کالا']])->assertOk()
            ->assertJsonPath('data.translations.ur.name', 'رنگ')->assertJsonPath("data.translations.ur.values.{$black}", 'کالا');

        // Not a value of this attribute; not allowed for a product editor.
        $other = $this->attribute('Size', 'size', 'select', ['M']);
        $this->putJson("/api/v1/attributes/{$colour->id}/translations", ['locale' => 'ur', 'name' => 'x', 'values' => [$this->valueId($other, 'M') => 'y']])->assertStatus(422);
        $role = Role::factory()->for($this->store)->create(['slug' => 'editor-'.uniqid()]);
        foreach (['products.view', 'products.update'] as $key) {
            DB::table('permission_role')->insert(['role_id' => $role->id, 'permission_id' => Permission::query()->firstOrCreate(['key' => $key], ['group' => 'catalog', 'description' => 'x'])->id]);
        }
        $editor = User::factory()->create();
        $this->store->users()->attach($editor, ['role_id' => $role->id, 'status' => 'active']);
        $this->actingAs($editor)->putJson("/api/v1/attributes/{$colour->id}/translations", ['locale' => 'ur', 'name' => 'x'])->assertForbidden();

        $headers = ['X-Store-Slug' => $this->store->slug];
        $this->app['auth']->forgetGuards();
        $ur = $this->getJson('/api/v1/storefront/products?category=phones&lang=ur', $headers)->assertOk()->json('data.filters.0');
        $this->assertSame(['رنگ', 'کالا', 'black'], [$ur['name'], $ur['options'][0]['value'], $ur['options'][0]['slug']], 'the address keeps the stable slug');
        $this->assertSame(['رنگ', 'کالا'], array_values(array_intersect_key($this->getJson('/api/v1/storefront/products/phone?lang=ur', $headers)->json('data.product.specifications.0'), ['name' => 1, 'value' => 1])));
        $this->assertSame('Black', $this->getJson('/api/v1/storefront/products/phone?lang=en', $headers)->json('data.product.specifications.0.value'));
    }

    public function test_specifications_come_in_and_go_out_through_the_csv(): void
    {
        $ram = $this->attribute('RAM', 'ram', 'select', ['8 GB', '16 GB']);
        $features = $this->attribute('Features', 'features', 'multi_select', ['NFC', '5G']);
        $screen = $this->attribute('Screen', 'screen', 'numeric');
        $this->putJson("/api/v1/categories/{$this->phones->id}/attributes", ['attributes' => [['attribute_id' => $ram->id, 'is_required' => true]]])->assertOk();

        $preview = $this->post('/api/v1/products/import', ['file' => $this->csv([
            ['sku', 'name', 'category', 'attributes'],
            ['P-1', 'Pixel', 'Phones', 'ram: 16 GB; features: NFC, 5g; screen: 6.1'],
            ['P-2', 'Bad', 'Phones', 'ram: 64 GB; weight: 2'],
            ['P-3', 'No RAM', 'Phones', 'screen: 6.7'],
        ])], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertSame(['create', 'invalid', 'create'], array_column($preview['rows'], 'status'));
        $this->assertStringContainsString('"64 GB" is not a value of RAM', implode(' ', $preview['rows'][1]['messages']));
        $this->assertStringContainsString('no attribute "weight"', implode(' ', $preview['rows'][1]['messages']));

        $result = $this->postJson("/api/v1/products/import/{$preview['id']}/confirm")->assertOk()->json('data');
        $this->assertSame(1, $result['created']);
        $this->assertSame(4, $result['skipped'][0]['row']);
        $this->assertStringContainsString('Required', $result['skipped'][0]['reason']);
        $this->assertNull(Product::query()->where('sku', 'P-3')->first(), 'the row is undone as a whole');

        $pixel = Product::query()->where('sku', 'P-1')->firstOrFail();
        $specs = collect($this->getJson("/api/v1/products/{$pixel->public_id}/specifications")->json('data.values'))->pluck('value', 'attribute_id');
        $this->assertSame($this->valueId($ram, '16 GB'), $specs[$ram->id]);
        $this->assertEqualsCanonicalizing([$this->valueId($features, 'NFC'), $this->valueId($features, '5G')], $specs[$features->id]);

        // Out, edited, back in: only the listed attribute changes.
        $csv = $this->get('/api/v1/products/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('ram: 16 GB', $csv);
        $edited = str_replace('ram: 16 GB', 'ram: 8 GB', $csv);
        $again = $this->post('/api/v1/products/import', ['file' => UploadedFile::fake()->createWithContent('e.csv', $edited)], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->postJson("/api/v1/products/import/{$again['id']}/confirm")->assertOk();
        $specs = collect($this->getJson("/api/v1/products/{$pixel->public_id}/specifications")->json('data.values'))->pluck('value', 'attribute_id');
        $this->assertSame($this->valueId($ram, '8 GB'), $specs[$ram->id]);
        $this->assertEquals(6.1, $specs[$screen->id]);
    }
}
