<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase B40 — Module 06 §48–51, §53: product import (staged, idempotent,
 * per-row) and export.
 */
final class ProductImportExportTest extends TestCase
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
        $this->storeCurrency($store, 'PKR');
        $package = Package::factory()->create();
        $package->entitlements()->create(['key' => 'products.basic', 'type' => EntitlementType::Feature, 'boolean_value' => true]);
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

    /** @param list<list<string>> $rows */
    private function csv(array $rows): UploadedFile
    {
        $handle = fopen('php://temp', 'w+b');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);

        return UploadedFile::fake()->createWithContent('products.csv', (string) stream_get_contents($handle));
    }

    /** @return array<string, mixed> preview data */
    private function preview(UploadedFile $file, ?User $user = null): array
    {
        return $this->actingAs($user ?? $this->owner)->post('/api/v1/products/import', ['file' => $file], ['Accept' => 'application/json'])->assertOk()->json('data');
    }

    private const HEADER = ['sku', 'name', 'status', 'price', 'sale_price', 'brand', 'category', 'tags', 'featured', 'parent_sku', 'options'];

    private function catalogFile(): UploadedFile
    {
        return $this->csv([
            self::HEADER,
            ['LAWN-1', 'Lawn Suit', 'active', '4,500', '3999.50', 'Gul Ahmed', 'Clothing > Women', 'Eid; Summer', 'yes', '', ''],
            ['ATR-1', 'Rose Attar', 'draft', '1200', '', '', 'Fragrance', '', 'no', '', ''],
            ['LAWN-1-S', '', 'active', '4500', '', '', '', '', '', 'LAWN-1', 'Size: S'],
            ['LAWN-1-M', '', 'active', '4700', '', '', '', '', '', 'LAWN-1', 'Size: M'],
            ['', '', 'active', 'abc', '', '', '', '', '', '', ''],
            ['ATR-1', 'Rose Attar again', '', '', '', '', '', '', '', '', ''],
        ]);
    }

    public function test_import_is_previewed_first_then_creates_products_variants_brands_categories_and_tags(): void
    {
        $preview = $this->preview($this->catalogFile());

        $this->assertSame(['rows' => 6, 'create' => 4, 'update' => 0, 'invalid' => 2, 'variants' => 2], $preview['totals']);
        $this->assertSame(['Gul Ahmed'], $preview['new_brands']);
        $this->assertEqualsCanonicalizing(['Clothing > Women', 'Fragrance'], $preview['new_categories']);
        $invalid = collect($preview['rows'])->where('status', 'invalid')->values();
        $this->assertSame([6, 7], $invalid->pluck('row')->all());
        $this->assertStringContainsString('not an amount', implode(' ', $invalid[0]['messages']));
        $this->assertStringContainsString('row 3', implode(' ', $invalid[1]['messages']));
        $this->assertSame(0, Product::query()->count(), 'nothing is written before the confirmation');

        $result = $this->postJson("/api/v1/products/import/{$preview['id']}/confirm")->assertOk()->json('data');
        $this->assertSame([2, 0, 2, 0, []], [$result['created'], $result['updated'], $result['variants_created'], $result['variants_updated'], $result['skipped']]);

        $lawn = Product::query()->where('sku', 'LAWN-1')->firstOrFail();
        $this->assertSame([450000, 399950, true, 'active'], [$lawn->price_minor, $lawn->sale_price_minor, $lawn->is_featured, $lawn->status->value]);
        $this->assertSame('Gul Ahmed', $lawn->brand?->name);
        $women = Category::query()->where('name', 'Women')->firstOrFail();
        $this->assertSame([$women->id, 'Clothing'], [$lawn->primary_category_id, Category::query()->find($women->parent_id)?->name]);
        $this->assertSame(['Eid', 'Summer'], $lawn->tags()->pluck('name')->all());
        $this->assertSame([['Size' => 'S'], ['Size' => 'M']], $lawn->variants()->orderBy('id')->pluck('option_values')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'products.imported']);

        // The same preview cannot be confirmed twice.
        $this->postJson("/api/v1/products/import/{$preview['id']}/confirm")->assertStatus(410);
    }

    public function test_importing_the_same_file_again_updates_and_never_duplicates(): void
    {
        foreach ([1, 2] as $round) {
            $preview = $this->preview($this->catalogFile());
            $this->postJson("/api/v1/products/import/{$preview['id']}/confirm")->assertOk();
        }

        $this->assertSame(2, Product::query()->count());
        $this->assertSame(2, ProductVariant::query()->count());
        $this->assertSame(1, Brand::query()->count());
        $this->assertSame(3, Category::query()->count());
        $this->assertSame(['rows' => 6, 'create' => 0, 'update' => 4, 'invalid' => 2, 'variants' => 2], $this->preview($this->catalogFile())['totals']);
    }

    public function test_an_exported_file_imports_back_by_id_and_formulas_are_neutralized(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id, 'name' => '=HYPERLINK("x")', 'sku' => 'F-1', 'price_minor' => 100000, 'currency' => 'PKR', 'cost_price_minor' => 50000]);
        [$other] = $this->storeWithOwner();
        Product::factory()->create(['store_id' => $other->id, 'name' => 'Other store product']);
        app(TenantContext::class)->resolveToStore($this->store->id);

        $csv = $this->actingAs($this->owner)->get('/api/v1/products/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('Other store product', $csv);
        $this->assertStringContainsString('cost_price', $csv);
        $this->assertStringContainsString('1000.00', $csv);

        $edited = str_replace('1000.00', '1250.00', $csv);
        $preview = $this->preview(UploadedFile::fake()->createWithContent('export.csv', $edited));
        $this->assertSame(1, $preview['totals']['update']);
        $this->postJson("/api/v1/products/import/{$preview['id']}/confirm")->assertOk();
        $this->assertSame(125000, $product->refresh()->price_minor);
        $this->assertSame('=HYPERLINK("x")', $product->name, 'the export\'s protective apostrophe is taken off again');

        // Without products.view_cost: no cost column out, and an incoming one is ignored.
        $viewer = $this->staffWith(['products.view', 'products.update']);
        $this->assertStringNotContainsString('cost_price', strtok($this->actingAs($viewer)->get('/api/v1/products/export')->streamedContent(), "\n"));
        $preview = $this->preview($this->csv([['sku', 'cost_price'], ['F-1', '1']]), $viewer);
        $this->assertStringContainsString('cost_price column is ignored', implode(' ', $preview['notices']));
        $this->postJson("/api/v1/products/import/{$preview['id']}/confirm")->assertOk();
        $this->assertSame(50000, $product->refresh()->cost_price_minor);
    }

    public function test_rows_past_the_package_limit_are_reported_not_created(): void
    {
        [$store, $owner] = $this->storeWithOwner(maxProducts: 2);
        $preview = $this->preview($this->csv([['sku', 'name'], ['A', 'One'], ['B', 'Two'], ['C', 'Three']]), $owner);
        $this->assertStringContainsString('allows 2 more products', implode(' ', $preview['notices']));

        $result = $this->actingAs($owner)->postJson("/api/v1/products/import/{$preview['id']}/confirm")->assertOk()->json('data');
        $this->assertSame(2, $result['created']);
        $this->assertSame(4, $result['skipped'][0]['row']);
        $this->assertStringContainsString('limit', $result['skipped'][0]['reason']);
        $this->assertSame(2, Product::query()->where('store_id', $store->id)->count());
    }

    public function test_permissions_and_store_boundaries_are_kept(): void
    {
        $this->actingAs($this->staffWith(['products.view']))->post('/api/v1/products/import', ['file' => $this->csv([['name'], ['X']])], ['Accept' => 'application/json'])->assertForbidden();

        // An editor may update but not create.
        $editor = $this->staffWith(['products.view', 'products.update']);
        Product::factory()->create(['store_id' => $this->store->id, 'sku' => 'KEEP', 'name' => 'Keep']);
        $preview = $this->preview($this->csv([['sku', 'name'], ['KEEP', 'Kept'], ['NEW', 'New one']]), $editor);
        $this->assertSame(['update', 'invalid'], array_column($preview['rows'], 'status'));

        // Another store's product id is not found here; nor can another user confirm this preview.
        [$other, $otherOwner] = $this->storeWithOwner();
        $foreign = Product::factory()->create(['store_id' => $other->id]);
        app(TenantContext::class)->resolveToStore($this->store->id);
        $preview = $this->preview($this->csv([['id', 'name'], [$foreign->public_id, 'Mine now']]));
        $this->assertSame('invalid', $preview['rows'][0]['status']);
        $ok = $this->preview($this->csv([['name'], ['Fine']]));
        $this->actingAs($editor)->postJson("/api/v1/products/import/{$ok['id']}/confirm")->assertStatus(410);

        // Unknown columns are refused before anything is read.
        $this->actingAs($this->owner)->post('/api/v1/products/import', ['file' => $this->csv([['name', 'store_id'], ['X', '1']])], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('code', 'bad_header');
        $this->assertNotNull($otherOwner);
    }
}
