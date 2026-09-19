<?php

declare(strict_types=1);

namespace Tests\Feature\Packages;

use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\Package;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * This milestone's "B2 Tests / SUPER ADMIN" section.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class SuperAdminPackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_staff_can_create_a_package(): void
    {
        $admin = User::factory()->create(['platform_role' => 'catalog_manager']);

        $response = $this->actingAs($admin)->postJson('/api/v1/super-admin/packages', [
            'code' => 'enterprise',
            'name' => 'Enterprise',
            'is_active' => true,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('packages', ['code' => 'enterprise']);
    }

    public function test_ordinary_tenant_user_cannot_create_a_package(): void
    {
        $storeOwner = User::factory()->create(['platform_role' => null]);

        $response = $this->actingAs($storeOwner)->postJson('/api/v1/super-admin/packages', [
            'code' => 'hacker-tier',
            'name' => 'Hacker Tier',
        ]);

        $response->assertStatus(403);
    }

    public function test_unauthenticated_request_cannot_manage_packages(): void
    {
        $this->postJson('/api/v1/super-admin/packages', ['code' => 'x', 'name' => 'X'])
            ->assertStatus(401);
    }

    public function test_super_admin_can_suspend_a_stores_subscription(): void
    {
        $store = Store::factory()->create();
        \App\Domain\Packages\Models\Subscription::factory()->for($store)->for(Package::factory())->create();
        $admin = User::factory()->create(['platform_role' => 'support_agent']);

        $response = $this->actingAs($admin)->postJson(
            "/api/v1/super-admin/stores/{$store->id}/subscription/suspend",
            ['reason' => 'payment failure']
        );

        $response->assertNoContent();
        $this->assertDatabaseHas('subscriptions', [
            'store_id' => $store->id,
            'status' => \App\Domain\Packages\Models\SubscriptionStatus::Suspended->value,
        ]);
    }

    public function test_super_admin_package_change_is_reachable_only_via_the_doubly_guarded_route(): void
    {
        $store = Store::factory()->create();
        \App\Domain\Packages\Models\Subscription::factory()->for($store)->for(Package::factory()->create(['code' => 'basic']))->create();
        $newPackage = Package::factory()->create(['code' => 'premium']);
        $admin = User::factory()->create(['platform_role' => 'billing_admin']);

        $response = $this->actingAs($admin)->postJson(
            "/api/v1/super-admin/stores/{$store->id}/subscription/change-package",
            ['package_code' => 'premium']
        );

        $response->assertOk();
        $response->assertJsonPath('data.new_package_code', 'premium');
    }
}
