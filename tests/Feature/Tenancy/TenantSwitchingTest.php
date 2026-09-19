<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * This milestone's "Test Specification / Tenant switching" section:
 * member can access authorized store; non-member cannot switch into
 * store; inactive membership cannot gain access.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class TenantSwitchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_switch_into_a_store_they_belong_to(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $roleA = Role::factory()->for($storeA)->create();
        $roleB = Role::factory()->for($storeB)->create();
        $user = User::factory()->create();
        $storeA->users()->attach($user, ['role_id' => $roleA->id, 'status' => 'active']);
        $storeB->users()->attach($user, ['role_id' => $roleB->id, 'status' => 'active']);

        $response = $this->actingAs($user)->postJson('/api/v1/store/switch', ['store_id' => $storeB->id]);

        $response->assertOk();
        $response->assertJson(['data' => ['active_store_id' => $storeB->id]]);
    }

    public function test_non_member_cannot_switch_into_a_store(): void
    {
        $foreignStore = Store::factory()->create();
        $user = User::factory()->create(); // no membership anywhere

        $response = $this->actingAs($user)->postJson('/api/v1/store/switch', ['store_id' => $foreignStore->id]);

        $response->assertStatus(422)->assertJsonValidationErrors('store_id');
    }

    public function test_suspended_membership_cannot_switch_into_that_store(): void
    {
        $store = Store::factory()->create();
        $role = Role::factory()->for($store)->create();
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'suspended']);

        $response = $this->actingAs($user)->postJson('/api/v1/store/switch', ['store_id' => $store->id]);

        $response->assertStatus(422)->assertJsonValidationErrors('store_id');
    }

    public function test_switching_requires_authentication(): void
    {
        $store = Store::factory()->create();

        $this->postJson('/api/v1/store/switch', ['store_id' => $store->id])->assertStatus(401);
    }
}
