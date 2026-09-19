<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B3 — Brand + Attribute (Module 07 §21-26, §27-38).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class BrandAndAttributeTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Store $store): User
    {
        $role = Role::factory()->for($store)->create(['slug' => 'owner']);
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_owner_can_create_a_brand(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->postJson('/api/v1/brands', ['name' => 'Acme Co']);

        $response->assertCreated();
        $this->assertDatabaseHas('brands', ['store_id' => $store->id, 'name' => 'Acme Co']);
    }

    public function test_attribute_values_are_normalized_to_prevent_case_duplicates(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->postJson('/api/v1/attributes', [
            'name' => 'Color', 'key' => 'color', 'type' => 'select', 'values' => ['Black', 'White'],
        ]);

        $response->assertCreated();
        $attributeId = \App\Domain\Catalog\Models\Attribute::query()->where('key', 'color')->value('id');

        $this->assertDatabaseHas('attribute_values', ['attribute_id' => $attributeId, 'normalized_value' => 'black']);
        $this->assertDatabaseHas('attribute_values', ['attribute_id' => $attributeId, 'normalized_value' => 'white']);
    }

    public function test_duplicate_attribute_value_is_rejected_regardless_of_case(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $attribute = \App\Domain\Catalog\Models\Attribute::factory()->for($store)->create(['key' => 'size']);
        $attribute->values()->create(['value' => 'Small', 'normalized_value' => 'small', 'sort_order' => 0]);

        // Direct model-level uniqueness check (the DB constraint is the
        // actual enforcement mechanism — this asserts the constraint
        // exists and is keyed correctly, without needing a dedicated API
        // "add single value" endpoint in B3's scope).
        $this->expectException(\Illuminate\Database\QueryException::class);
        $attribute->values()->create(['value' => 'small', 'normalized_value' => 'small', 'sort_order' => 1]);
    }

    public function test_non_owner_without_permission_cannot_create_brands(): void
    {
        $store = Store::factory()->create();
        $role = Role::factory()->for($store)->create(['slug' => 'staff']);
        $staff = User::factory()->create();
        $store->users()->attach($staff, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($staff)->postJson('/api/v1/brands', ['name' => 'X'])->assertStatus(403);
    }
}
