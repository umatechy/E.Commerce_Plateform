<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase B17 — Staff store-settings administration, tenant isolation,
 * staff/customer boundary regression (Module 33 §19/§39, Non-
 * Negotiable).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class StoreSettingAdminTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Store $store): User
    {
        $role = $this->systemRole($store, 'owner');
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_owner_can_view_store_settings(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->getJson('/api/v1/store/settings');

        $response->assertOk();
    }

    public function test_owner_can_update_store_timezone(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->putJson('/api/v1/store/settings/store.timezone', ['value' => 'Asia/Karachi']);

        $response->assertOk();
        $response->assertJsonPath('data.value', 'Asia/Karachi');
    }

    public function test_platform_scope_key_cannot_be_written_through_the_store_endpoint(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->putJson('/api/v1/store/settings/platform.maintenance_mode', ['value' => true]);

        $response->assertStatus(404); // not a store-scope key at all through this endpoint's lookup
    }

    public function test_manager_without_settings_manage_cannot_update_settings(): void
    {
        $store = Store::factory()->create();
        $role = Role::factory()->for($store)->create(['slug' => 'no-settings-access']);
        $staff = User::factory()->create();
        $store->users()->attach($staff, ['role_id' => $role->id, 'status' => 'active']);

        $response = $this->actingAs($staff)->putJson('/api/v1/store/settings/store.timezone', ['value' => 'Asia/Karachi']);

        $response->assertStatus(403);
    }

    public function test_store_a_settings_update_never_affects_store_b(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);

        $this->actingAs($ownerA)->putJson('/api/v1/store/settings/store.timezone', ['value' => 'Asia/Karachi'])->assertOk();

        app(\App\Domain\Tenancy\Support\TenantContext::class)->resolveToStore($storeB->id);
        $valueForB = app(\App\Domain\Settings\Services\ConfigService::class)->get('store.timezone');
        $this->assertSame('UTC', $valueForB);
    }

    public function test_customer_token_cannot_access_staff_settings_routes(): void
    {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/store/settings');

        $response->assertStatus(401);
    }
}
