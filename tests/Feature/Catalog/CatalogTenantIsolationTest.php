<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B3 — Module 06 §3 "Tenant Isolation": "A Store A request must
 * never be able to read, modify, delete, or index Store B catalog
 * data." Mirrors the pattern established in Phase B1's
 * TenantIsolationTest / RoleAuthorizationTest.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class CatalogTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Store $store): User
    {
        $role = $this->systemRole($store, 'owner');
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_store_a_cannot_read_store_bs_product(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        $productB = Product::factory()->for($storeB)->create();

        $this->actingAs($ownerA)->getJson("/api/v1/products/{$productB->id}")->assertStatus(404);
    }

    public function test_store_a_cannot_update_store_bs_product(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        $productB = Product::factory()->for($storeB)->create(['name' => 'Original']);

        $response = $this->actingAs($ownerA)->putJson("/api/v1/products/{$productB->id}", ['name' => 'Hacked']);

        $response->assertStatus(404);
        $this->assertDatabaseHas('products', ['id' => $productB->id, 'name' => 'Original']);
    }

    public function test_store_a_cannot_delete_store_bs_product(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        $productB = Product::factory()->for($storeB)->create();

        $this->actingAs($ownerA)->deleteJson("/api/v1/products/{$productB->id}")->assertStatus(404);
        $this->assertDatabaseHas('products', ['id' => $productB->id, 'deleted_at' => null]);
    }

    public function test_product_listing_never_includes_another_stores_products(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        \App\Domain\Identity\Models\Permission::query()->firstOrCreate(['key' => 'products.view'], ['group' => 'products']);
        Product::factory()->for($storeA)->create(['name' => 'Mine']);
        $foreign = Product::factory()->for($storeB)->create(['name' => 'TheirsOnly']);

        $response = $this->actingAs($ownerA)->getJson('/api/v1/products');

        $response->assertOk();
        $response->assertJsonMissing(['name' => 'TheirsOnly']);
    }

    public function test_store_a_cannot_assign_a_product_to_store_bs_category(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        $foreignCategory = Category::factory()->for($storeB)->create();

        $response = $this->actingAs($ownerA)->postJson('/api/v1/products', [
            'type' => 'simple', 'name' => 'X', 'primary_category_id' => $foreignCategory->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('primary_category_id');
    }

    public function test_store_a_cannot_assign_a_product_to_store_bs_brand(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        $foreignBrand = Brand::factory()->for($storeB)->create();

        $response = $this->actingAs($ownerA)->postJson('/api/v1/products', [
            'type' => 'simple', 'name' => 'X', 'brand_id' => $foreignBrand->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('brand_id');
    }

    public function test_store_a_variant_creation_cannot_target_store_bs_product(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        $productB = Product::factory()->for($storeB)->create();

        $response = $this->actingAs($ownerA)->postJson("/api/v1/products/{$productB->id}/variants", ['sku' => 'X-1']);

        $response->assertStatus(404);
    }
}
