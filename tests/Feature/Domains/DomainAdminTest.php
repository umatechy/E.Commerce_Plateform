<?php

declare(strict_types=1);

namespace Tests\Feature\Domains;

use App\Domain\Domains\Models\Domain;
use App\Domain\Domains\Models\DomainStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase B14 — Staff/Super Admin domain administration, tenant
 * isolation, staff/customer boundary regression (Module 19 §30-32,
 * Non-Negotiable).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class DomainAdminTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Store $store): User
    {
        $role = $this->systemRole($store, 'owner');
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_owner_can_add_a_custom_domain(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->postJson('/api/v1/domains', ['hostname' => 'shop.mystore.com']);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'pending');
    }

    public function test_invalid_hostname_is_rejected_with_a_clear_error(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->postJson('/api/v1/domains', ['hostname' => 'https://not-a-hostname/x']);

        $response->assertStatus(422)->assertJsonPath('code', 'invalid_hostname');
    }

    public function test_manager_without_domains_manage_cannot_add_a_domain(): void
    {
        $store = Store::factory()->create();
        $role = $this->systemRole($store, 'manager');
        $manager = User::factory()->create();
        $store->users()->attach($manager, ['role_id' => $role->id, 'status' => 'active']);

        $response = $this->actingAs($manager)->postJson('/api/v1/domains', ['hostname' => 'shop.mystore.com']);

        $response->assertStatus(403);
    }

    public function test_store_a_cannot_set_store_bs_domain_as_primary(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        $domainB = Domain::factory()->for($storeB)->create(['status' => DomainStatus::Verified]);

        $response = $this->actingAs($ownerA)->postJson("/api/v1/domains/{$domainB->id}/primary");

        $response->assertStatus(403);
    }

    public function test_customer_token_cannot_access_staff_domain_routes(): void
    {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/domains');

        $response->assertStatus(401);
    }

    public function test_super_admin_can_suspend_any_stores_domain(): void
    {
        $store = Store::factory()->create();
        $domain = Domain::factory()->for($store)->create(['status' => DomainStatus::Active]);
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $response = $this->actingAs($superAdmin)->postJson("/api/v1/super-admin/stores/{$store->id}/domains/{$domain->id}/suspend");

        $response->assertOk();
        $this->assertSame('suspended', $domain->fresh()->status->value);
    }

    public function test_ordinary_store_owner_cannot_reach_super_admin_domain_routes(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $domain = Domain::query()->withoutTenantScope()->where('store_id', $store->id)->firstOrFail();

        $response = $this->actingAs($owner)->postJson("/api/v1/super-admin/stores/{$store->id}/domains/{$domain->id}/suspend");

        $response->assertStatus(403);
    }
}
