<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Category;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B3 — Category hierarchy / parenting rules (Module 07 §8).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class CategoryTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Store $store): User
    {
        $role = Role::factory()->for($store)->create(['slug' => 'owner']);
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_owner_can_create_a_root_category(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->postJson('/api/v1/categories', ['name' => 'Electronics']);

        $response->assertCreated();
        $this->assertDatabaseHas('categories', ['store_id' => $store->id, 'name' => 'Electronics', 'parent_id' => null]);
    }

    public function test_category_can_be_created_under_a_parent(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $parent = Category::factory()->for($store)->create();

        $response = $this->actingAs($owner)->postJson('/api/v1/categories', [
            'name' => 'Mobile Phones', 'parent_id' => $parent->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('categories', ['name' => 'Mobile Phones', 'parent_id' => $parent->id]);
    }

    public function test_category_cannot_become_its_own_parent(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $category = Category::factory()->for($store)->create();

        $response = $this->actingAs($owner)->putJson("/api/v1/categories/{$category->id}", [
            'name' => $category->name, 'parent_id' => $category->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_category_cannot_be_moved_under_its_own_descendant(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $grandparent = Category::factory()->for($store)->create();
        $parent = Category::factory()->for($store)->create(['parent_id' => $grandparent->id]);

        // Attempt to move grandparent under its own child (parent) — a cycle.
        $response = $this->actingAs($owner)->putJson("/api/v1/categories/{$grandparent->id}", [
            'name' => $grandparent->name, 'parent_id' => $parent->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_category_cannot_reference_a_parent_from_another_store(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        $foreignCategory = Category::factory()->for($storeB)->create();

        $response = $this->actingAs($ownerA)->postJson('/api/v1/categories', [
            'name' => 'Should Fail', 'parent_id' => $foreignCategory->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_deleting_a_category_nulls_childrens_parent_reference(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $parent = Category::factory()->for($store)->create();
        $child = Category::factory()->for($store)->create(['parent_id' => $parent->id]);

        $this->actingAs($owner)->deleteJson("/api/v1/categories/{$parent->id}")->assertNoContent();

        $this->assertDatabaseHas('categories', ['id' => $child->id, 'parent_id' => null]);
    }

    public function test_non_owner_without_permission_cannot_manage_categories(): void
    {
        $store = Store::factory()->create();
        $role = Role::factory()->for($store)->create(['slug' => 'staff']);
        $staff = User::factory()->create();
        $store->users()->attach($staff, ['role_id' => $role->id, 'status' => 'active']);

        $response = $this->actingAs($staff)->postJson('/api/v1/categories', ['name' => 'X']);

        $response->assertStatus(403);
    }
}
