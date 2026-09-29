<?php

declare(strict_types=1);

namespace Tests\Feature\Packages;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * This milestone's "B2 Tests / TENANT ISOLATION" section — Store A must
 * never access, or affect the resolution of, Store B's subscription,
 * entitlements, or usage.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class PackageTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Store $store): User
    {
        $role = $this->systemRole($store, 'owner');
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_store_a_cannot_view_store_bs_subscription(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        Subscription::factory()->for($storeA)->for(Package::factory())->create();
        $subscriptionB = Subscription::factory()->for($storeB)->for(Package::factory())->create();
        $ownerA = $this->ownerOf($storeA);

        // /api/v1/subscription always resolves the AUTHENTICATED user's
        // own active store — there is no ID parameter to manipulate, so
        // this proves isolation structurally rather than via a guessed
        // ID (a stronger guarantee than "guessing fails").
        $response = $this->actingAs($ownerA)->getJson('/api/v1/subscription');

        $response->assertOk();
        $response->assertJsonMissing(['package' => ['code' => $subscriptionB->package->code]]);
    }

    public function test_store_a_usage_is_never_visible_to_store_b(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $packageA = Package::factory()->create();
        $packageA->entitlements()->create([
            'key' => 'max_widgets', 'type' => \App\Domain\Packages\Models\EntitlementType::UsageLimit, 'limit_value' => 10,
        ]);
        Subscription::factory()->for($storeA)->for($packageA)->create();
        Subscription::factory()->for($storeB)->for(Package::factory())->create();

        $this->app->make(\App\Domain\Tenancy\Support\TenantContext::class)->resolveToStore($storeA->id);
        $this->app->make(\App\Domain\Packages\Services\EntitlementService::class)->recordUsage('max_widgets', 7);

        // A fresh EntitlementService bound to Store B's context must
        // never see Store A's usage counter, even for the same metric key.
        $freshContext = new \App\Domain\Tenancy\Support\TenantContext();
        $freshContext->resolveToStore($storeB->id);
        $serviceForB = new \App\Domain\Packages\Services\EntitlementService(
            $freshContext,
            new \App\Domain\Packages\Services\UsageTrackingService($freshContext)
        );

        $this->assertSame(0, $serviceForB->currentUsage('max_widgets'));
    }

    public function test_store_a_owner_cannot_change_store_bs_package_via_super_admin_route_without_platform_role(): void
    {
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf(Store::factory()->create());
        $newPackage = Package::factory()->create(['code' => 'premium']);

        $response = $this->actingAs($ownerA)->postJson(
            "/api/v1/super-admin/stores/{$storeB->id}/subscription/change-package",
            ['package_code' => $newPackage->code]
        );

        $response->assertStatus(403);
    }
}
