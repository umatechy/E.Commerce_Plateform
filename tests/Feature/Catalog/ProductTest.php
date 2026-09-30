<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B3 — Product CRUD, cost-price permission gating, publishing.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class ProductTest extends TestCase
{
    use RefreshDatabase;

    private function storeWithOwner(?int $maxProducts = null): array
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create();
        $package->entitlements()->create([
            'key' => 'products.basic', 'type' => EntitlementType::Feature, 'boolean_value' => true,
        ]);
        if ($maxProducts !== null) {
            $package->entitlements()->create([
                'key' => 'max_products', 'type' => EntitlementType::UsageLimit, 'limit_value' => $maxProducts,
            ]);
        }
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);

        $role = $this->systemRole($store, 'owner');
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $role->id, 'status' => 'active']);

        return [$store, $owner];
    }

    public function test_owner_can_create_a_simple_product(): void
    {
        [$store, $owner] = $this->storeWithOwner();

        $response = $this->actingAs($owner)->postJson('/api/v1/products', [
            'type' => 'simple',
            'name' => 'Cotton T-Shirt',
            'sku' => 'TSH-001',
            'price_minor' => 2500,
            'currency' => 'USD',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('products', ['store_id' => $store->id, 'name' => 'Cotton T-Shirt', 'sku' => 'TSH-001']);
    }

    public function test_product_without_feature_entitlement_is_rejected(): void
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create(); // no products.basic entitlement at all
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $role = $this->systemRole($store, 'owner');
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $role->id, 'status' => 'active']);

        $response = $this->actingAs($owner)->postJson('/api/v1/products', ['type' => 'simple', 'name' => 'X']);

        $response->assertStatus(403);
    }

    public function test_product_creation_blocked_when_usage_limit_reached(): void
    {
        [$store, $owner] = $this->storeWithOwner(maxProducts: 1);

        $this->actingAs($owner)->postJson('/api/v1/products', ['type' => 'simple', 'name' => 'First Product'])
            ->assertCreated();

        $response = $this->actingAs($owner)->postJson('/api/v1/products', ['type' => 'simple', 'name' => 'Second Product']);

        $response->assertStatus(403);
        $response->assertJsonPath('code', 'usage_limit_exceeded');
    }

    public function test_archiving_a_product_frees_usage_quota_for_a_new_one(): void
    {
        [$store, $owner] = $this->storeWithOwner(maxProducts: 1);

        $first = $this->actingAs($owner)->postJson('/api/v1/products', ['type' => 'simple', 'name' => 'First'])
            ->json('data.id');

        $product = \App\Domain\Catalog\Models\Product::query()->withoutTenantScope()->where('public_id', $first)->firstOrFail();

        $this->actingAs($owner)->putJson("/api/v1/products/{$product->id}", ['status' => 'archived'])->assertOk();

        $this->actingAs($owner)->postJson('/api/v1/products', ['type' => 'simple', 'name' => 'Second'])
            ->assertCreated();
    }

    public function test_cost_price_is_hidden_from_user_without_permission(): void
    {
        [$store, ] = $this->storeWithOwner();
        $role = $this->systemRole($store, 'staff');
        $staff = User::factory()->create();
        $store->users()->attach($staff, ['role_id' => $role->id, 'status' => 'active']);
        \App\Domain\Identity\Models\Permission::query()->firstOrCreate(['key' => 'products.view'], ['group' => 'products']);
        $role->permissions()->attach(\App\Domain\Identity\Models\Permission::where('key', 'products.view')->first());

        $product = \App\Domain\Catalog\Models\Product::factory()->for($store)->create(['cost_price_minor' => 500]);

        $response = $this->actingAs($staff)->getJson("/api/v1/products/{$product->id}");

        $response->assertOk();
        $response->assertJsonMissingPath('data.cost_price_minor');
    }

    public function test_cost_price_is_visible_to_owner(): void
    {
        [$store, $owner] = $this->storeWithOwner();
        $product = \App\Domain\Catalog\Models\Product::factory()->for($store)->create(['cost_price_minor' => 500]);

        $response = $this->actingAs($owner)->getJson("/api/v1/products/{$product->id}");

        $response->assertOk();
        $response->assertJsonPath('data.cost_price_minor', 500);
    }

    public function test_sale_price_must_be_less_than_regular_price(): void
    {
        [$store, $owner] = $this->storeWithOwner();

        $response = $this->actingAs($owner)->postJson('/api/v1/products', [
            'type' => 'simple', 'name' => 'X', 'price_minor' => 1000, 'sale_price_minor' => 1500,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('sale_price_minor');
    }

    public function test_deleting_a_product_soft_deletes_it(): void
    {
        [$store, $owner] = $this->storeWithOwner();
        $product = \App\Domain\Catalog\Models\Product::factory()->for($store)->create();

        $this->actingAs($owner)->deleteJson("/api/v1/products/{$product->id}")->assertNoContent();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }
}
