<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * This milestone's "Test Specification / Authorization" section:
 * permission denied, role-based access, policy enforcement.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithRole(Store $store, string $slug, array $permissionKeys = []): User
    {
        $role = Role::factory()->for($store)->create(['slug' => $slug, 'name' => ucfirst($slug)]);

        if ($permissionKeys !== []) {
            foreach ($permissionKeys as $key) {
                $permission = Permission::query()->firstOrCreate(['key' => $key], ['group' => explode('.', $key)[0]]);
                $role->permissions()->attach($permission);
            }
        }

        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_owner_can_create_a_role(): void
    {
        $store = Store::factory()->create();
        $owner = $this->memberWithRole($store, 'owner');

        $response = $this->actingAs($owner)->postJson('/api/v1/roles', ['name' => 'Warehouse Staff']);

        $response->assertCreated();
        $this->assertDatabaseHas('roles', ['store_id' => $store->id, 'name' => 'Warehouse Staff']);
    }

    public function test_non_owner_without_permission_cannot_create_a_role(): void
    {
        $store = Store::factory()->create();
        $staff = $this->memberWithRole($store, 'staff', ['products.view']);

        $response = $this->actingAs($staff)->postJson('/api/v1/roles', ['name' => 'New Role']);

        $response->assertStatus(403);
    }

    public function test_user_with_roles_view_permission_can_list_roles(): void
    {
        $store = Store::factory()->create();
        $viewer = $this->memberWithRole($store, 'auditor', ['roles.view']);

        $response = $this->actingAs($viewer)->getJson('/api/v1/roles');

        $response->assertOk();
    }

    public function test_user_without_roles_view_permission_cannot_list_roles(): void
    {
        $store = Store::factory()->create();
        $staff = $this->memberWithRole($store, 'staff', ['products.view']);

        $response = $this->actingAs($staff)->getJson('/api/v1/roles');

        $response->assertStatus(403);
    }

    public function test_system_role_cannot_be_deleted_even_by_owner(): void
    {
        $store = Store::factory()->create();
        $owner = $this->memberWithRole($store, 'owner');
        $systemRole = Role::factory()->for($store)->create(['is_system' => true, 'slug' => 'manager']);

        $response = $this->actingAs($owner)->deleteJson("/api/v1/roles/{$systemRole->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('roles', ['id' => $systemRole->id]);
    }

    public function test_unauthenticated_request_cannot_access_role_endpoints(): void
    {
        $this->getJson('/api/v1/roles')->assertStatus(401);
    }
}
