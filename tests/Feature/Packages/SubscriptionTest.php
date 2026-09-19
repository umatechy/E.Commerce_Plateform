<?php

declare(strict_types=1);

namespace Tests\Feature\Packages;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * This milestone's "B2 Tests / SUBSCRIPTION" section, plus registration
 * integration (Module 04 §18's "every store must have a current
 * package entitlement").
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Store $store): User
    {
        $role = Role::factory()->for($store)->create(['slug' => 'owner']);
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_registration_creates_a_trial_subscription_on_the_default_trial_package(): void
    {
        \App\Domain\Packages\Models\Package::query()->create(['code' => 'basic', 'name' => 'Basic']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New Owner',
            'email' => 'newowner@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
            'store_name' => 'New Owner Store',
        ]);

        $response->assertCreated();

        $store = Store::query()->where('name', 'New Owner Store')->firstOrFail();
        $subscription = Subscription::query()->withoutTenantScope()->where('store_id', $store->id)->firstOrFail();

        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertNotNull($subscription->trial_ends_at);
    }

    public function test_owner_can_retrieve_their_own_subscription(): void
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create();
        Subscription::factory()->for($store)->for($package)->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->getJson('/api/v1/subscription');

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['status', 'grants_access', 'package']]);
    }

    public function test_subscription_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/subscription')->assertStatus(401);
    }

    public function test_grants_access_reflects_subscription_status_correctly(): void
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create();
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Suspended]);
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->getJson('/api/v1/subscription');

        $response->assertOk();
        $response->assertJsonPath('data.grants_access', false);
    }

    public function test_usage_overview_endpoint_lists_only_usage_limit_entitlements(): void
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create();
        $package->entitlements()->create([
            'key' => 'a_feature', 'type' => \App\Domain\Packages\Models\EntitlementType::Feature, 'boolean_value' => true,
        ]);
        $package->entitlements()->create([
            'key' => 'max_things', 'type' => \App\Domain\Packages\Models\EntitlementType::UsageLimit, 'limit_value' => 10,
        ]);
        Subscription::factory()->for($store)->for($package)->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->getJson('/api/v1/subscription/usage');

        $response->assertOk();
        $response->assertJsonMissingPath('data.a_feature');
        $response->assertJsonPath('data.max_things.limit', 10);
    }
}
