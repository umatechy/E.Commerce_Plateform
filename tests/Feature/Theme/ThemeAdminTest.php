<?php

declare(strict_types=1);

namespace Tests\Feature\Theme;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Theme\Models\StoreTheme;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase B15 — Staff theme administration, tenant isolation, staff/
 * customer boundary regression (Module 17 §4/§40-41, Non-Negotiable).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class ThemeAdminTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Store $store): User
    {
        $role = Role::factory()->for($store)->create(['slug' => 'owner']);
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_owner_can_view_their_own_theme(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->getJson('/api/v1/store/theme');

        $response->assertOk();
        $response->assertJsonPath('data.is_published', true);
    }

    public function test_owner_can_update_the_draft_configuration(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->putJson('/api/v1/store/theme/draft', [
            'config' => ['tokens' => ['primary' => '#FF00FF']],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.draft_config.tokens.primary', '#FF00FF');
    }

    public function test_invalid_config_is_rejected_with_a_clear_error(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->putJson('/api/v1/store/theme/draft', [
            'config' => ['tokens' => ['primary' => 'not-a-hex-color']],
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'invalid_theme_config');
    }

    public function test_manager_without_permission_cannot_update_theme(): void
    {
        $store = Store::factory()->create();
        $role = Role::factory()->for($store)->create(['slug' => 'no-theme-access']);
        $staff = User::factory()->create();
        $store->users()->attach($staff, ['role_id' => $role->id, 'status' => 'active']);

        $response = $this->actingAs($staff)->putJson('/api/v1/store/theme/draft', ['config' => []]);

        $response->assertStatus(403);
    }

    public function test_publishing_requires_theme_publish_permission(): void
    {
        $store = Store::factory()->create();
        $role = Role::factory()->for($store)->create(['slug' => 'no-publish']);
        \App\Domain\Identity\Models\Permission::query()->firstOrCreate(['key' => 'theme.manage'], ['group' => 'theme', 'description' => 'x']);
        $permission = \App\Domain\Identity\Models\Permission::query()->where('key', 'theme.manage')->first();
        \Illuminate\Support\Facades\DB::table('permission_role')->insert(['role_id' => $role->id, 'permission_id' => $permission->id]);
        $staff = User::factory()->create();
        $store->users()->attach($staff, ['role_id' => $role->id, 'status' => 'active']);

        $response = $this->actingAs($staff)->postJson('/api/v1/store/theme/publish');

        $response->assertStatus(403);
    }

    public function test_store_a_only_ever_sees_their_own_theme_never_store_bs(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        $storeThemeB = StoreTheme::query()->where('store_id', $storeB->id)->firstOrFail();
        app(\App\Domain\Tenancy\Support\TenantContext::class)->resolveToStore($storeB->id);
        app(\App\Domain\Theme\Services\ThemeService::class)->updateDraft($storeThemeB, ['tokens' => ['primary' => '#B00B00']], null);

        $response = $this->actingAs($ownerA)->getJson('/api/v1/store/theme');

        $response->assertOk();
        $response->assertJsonMissing(['primary' => '#B00B00']);
    }

    public function test_customer_token_cannot_access_staff_theme_routes(): void
    {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/store/theme');

        $response->assertStatus(401);
    }

    public function test_basic_tier_store_cannot_set_custom_css(): void
    {
        // Module 17 §18/§46 "Custom CSS / Package Entitlement" —
        // regression test for the exact fix found during this
        // milestone's own security review (see
        // docs/security/b15-security-review.md "Issues Found and
        // Fixed" #3): custom_css must be entitlement-gated.
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $basicPackage = \App\Domain\Packages\Models\Package::factory()->create(['code' => 'basic-test']);
        \App\Domain\Packages\Models\Subscription::query()->updateOrCreate(
            ['store_id' => $store->id],
            ['package_id' => $basicPackage->id, 'status' => \App\Domain\Packages\Models\SubscriptionStatus::Active],
        );

        $response = $this->actingAs($owner)->putJson('/api/v1/store/theme/draft', [
            'config' => [], 'custom_css' => '.foo { color: red; }',
        ]);

        $response->assertStatus(403)->assertJsonPath('code', 'feature_not_entitled');
    }

    public function test_a_plain_configuration_update_with_no_custom_css_never_triggers_the_entitlement_check(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->putJson('/api/v1/store/theme/draft', ['config' => ['tokens' => ['primary' => '#010101']]]);

        $response->assertOk();
    }
}
