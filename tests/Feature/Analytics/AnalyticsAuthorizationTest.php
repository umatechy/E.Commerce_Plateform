<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase B12 — Role-based report access, sensitive financial-report
 * separation, staff/customer boundary regression (Module 22 §35-36).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class AnalyticsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Store $store): User
    {
        $role = $this->systemRole($store, 'owner');
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_owner_can_view_the_dashboard_with_financial_figures(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->getJson('/api/v1/dashboard?date_filter=this_month');

        $response->assertOk();
        $this->assertArrayHasKey('revenue_minor', $response->json('data'));
    }

    public function test_staff_with_only_view_permission_does_not_see_financial_figures(): void
    {
        $store = Store::factory()->create();
        $role = Role::factory()->for($store)->create(['slug' => 'view-only']);
        $staff = User::factory()->create();
        $store->users()->attach($staff, ['role_id' => $role->id, 'status' => 'active']);
        \App\Domain\Identity\Models\Permission::query()->firstOrCreate(['key' => 'analytics.view'], ['group' => 'analytics', 'description' => 'x']);
        $permissionId = \App\Domain\Identity\Models\Permission::query()->where('key', 'analytics.view')->value('id');
        \Illuminate\Support\Facades\DB::table('permission_role')->insert(['role_id' => $role->id, 'permission_id' => $permissionId]);

        $response = $this->actingAs($staff)->getJson('/api/v1/dashboard?date_filter=this_month');

        $response->assertOk();
        $this->assertArrayNotHasKey('revenue_minor', $response->json('data'));
    }

    public function test_dashboard_requires_analytics_view_permission(): void
    {
        $store = Store::factory()->create();
        $role = Role::factory()->for($store)->create(['slug' => 'no-analytics']);
        $staff = User::factory()->create();
        $store->users()->attach($staff, ['role_id' => $role->id, 'status' => 'active']);

        $response = $this->actingAs($staff)->getJson('/api/v1/dashboard?date_filter=this_month');

        $response->assertStatus(403);
    }

    public function test_sales_report_requires_financial_permission_even_with_view_permission(): void
    {
        $store = Store::factory()->create();
        $role = Role::factory()->for($store)->create(['slug' => 'view-only-2']);
        $staff = User::factory()->create();
        $store->users()->attach($staff, ['role_id' => $role->id, 'status' => 'active']);
        $permission = \App\Domain\Identity\Models\Permission::query()->firstOrCreate(['key' => 'analytics.view'], ['group' => 'analytics', 'description' => 'x']);
        \Illuminate\Support\Facades\DB::table('permission_role')->insert(['role_id' => $role->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs($staff)->getJson('/api/v1/reports/sales?date_filter=this_month');

        $response->assertStatus(403);
    }

    public function test_customer_token_cannot_access_staff_analytics_routes(): void
    {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/dashboard');

        $response->assertStatus(401);
    }

    public function test_invalid_date_filter_returns_a_clear_error(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->getJson('/api/v1/dashboard?date_filter=not_real');

        $response->assertStatus(422);
    }
}
