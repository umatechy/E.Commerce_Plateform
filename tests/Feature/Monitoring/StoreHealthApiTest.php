<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Monitoring\Models\StoreHealthSnapshot;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase B21 — a store's own health endpoints: permission-gated,
 * staff-only, and always scoped to the caller's own store.
 */
final class StoreHealthApiTest extends TestCase
{
    use RefreshDatabase;

    private function memberOf(Store $store, Role $role): User
    {
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_the_owner_sees_the_live_health_report(): void
    {
        $store = Store::factory()->create();
        $owner = $this->memberOf($store, $this->systemRole($store, 'owner'));

        $response = $this->actingAs($owner)->getJson('/api/v1/store/health');

        $response->assertOk()
            ->assertJsonStructure(['data' => ['status', 'checked_at', 'checks' => [['key', 'status', 'message', 'metrics']]]])
            ->assertJsonPath('data.checks.0.key', 'setup');
    }

    public function test_a_role_granted_store_health_view_can_read_it(): void
    {
        $store = Store::factory()->create();
        $role = Role::factory()->for($store)->create(['slug' => 'operations']);
        $role->permissions()->attach(Permission::query()->firstOrCreate(['key' => 'store_health.view'], ['group' => 'store_health']));

        $this->actingAs($this->memberOf($store, $role))->getJson('/api/v1/store/health')->assertOk();
    }

    public function test_staff_without_the_permission_is_refused(): void
    {
        $store = Store::factory()->create();
        $staff = $this->memberOf($store, $this->systemRole($store, 'staff'));

        $this->actingAs($staff)->getJson('/api/v1/store/health')->assertForbidden();
        $this->actingAs($staff)->getJson('/api/v1/store/health/history')->assertForbidden();
    }

    public function test_a_customer_token_cannot_reach_store_health(): void
    {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);

        $this->withHeader('Authorization', 'Bearer '.$customer->createToken('t')->plainTextToken)
            ->getJson('/api/v1/store/health')
            ->assertUnauthorized();
    }

    public function test_history_only_ever_lists_the_callers_own_store(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        StoreHealthSnapshot::query()->create(['store_id' => $storeA->id, 'overall_status' => 'ok', 'checks' => []]);
        StoreHealthSnapshot::query()->create(['store_id' => $storeB->id, 'overall_status' => 'critical', 'checks' => []]);
        $ownerA = $this->memberOf($storeA, $this->systemRole($storeA, 'owner'));

        $response = $this->actingAs($ownerA)->getJson('/api/v1/store/health/history');

        $response->assertOk();
        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame('ok', $response->json('data.data.0.status'));
        $this->assertArrayNotHasKey('store_id', $response->json('data.data.0'));
    }
}
