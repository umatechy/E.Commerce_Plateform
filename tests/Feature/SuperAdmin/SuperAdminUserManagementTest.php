<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase B16 — User & Staff Oversight: platform-wide account lock, kept
 * structurally separate from per-store role-membership status and
 * from the Customer guard entirely (Module 30 §12, Non-Negotiable).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class SuperAdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_staff_can_search_users(): void
    {
        User::factory()->create(['email' => 'findme@example.com']);
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $response = $this->actingAs($superAdmin)->getJson('/api/v1/super-admin/users?search=findme');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data.data')));
    }

    public function test_deactivating_a_user_prevents_future_login(): void
    {
        $user = User::factory()->create(['email' => 'locked@example.com', 'password' => Hash::make('secret123')]);
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $this->actingAs($superAdmin)->postJson("/api/v1/super-admin/users/{$user->id}/deactivate")->assertStatus(204);

        $response = $this->postJson('/api/v1/login', ['email' => 'locked@example.com', 'password' => 'secret123']);

        $response->assertStatus(422);
    }

    public function test_reactivating_a_user_restores_login(): void
    {
        $user = User::factory()->create(['email' => 'restored@example.com', 'password' => Hash::make('secret123'), 'is_active' => false]);
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $this->actingAs($superAdmin)->postJson("/api/v1/super-admin/users/{$user->id}/reactivate")->assertStatus(204);

        $response = $this->postJson('/api/v1/login', ['email' => 'restored@example.com', 'password' => 'secret123']);

        $response->assertOk();
    }

    public function test_deactivation_never_touches_the_customer_guard(): void
    {
        // Non-Negotiable regression: Super Admin's user-deactivation
        // capability must never reach into Customer at all — Customer
        // has no `is_active` column of its own, and this controller's
        // every method operates exclusively on App\Domain\Identity\Models\User.
        $store = \App\Domain\Tenancy\Models\Store::factory()->create();
        $customer = \App\Domain\Orders\Models\Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);
        $staffUser = User::factory()->create();

        $this->actingAs($superAdmin)->postJson("/api/v1/super-admin/users/{$staffUser->id}/deactivate")->assertStatus(204);

        // The customer's own login path (a completely separate guard/
        // controller since Phase B6) remains fully functional —
        // deactivating an unrelated User row has no bearing on it.
        $token = $customer->createToken('t')->plainTextToken;
        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/customer/notifications');
        $response->assertOk();
    }

    public function test_non_platform_staff_cannot_deactivate_users(): void
    {
        $user = User::factory()->create();
        $ordinaryUser = User::factory()->create(['platform_role' => null]);

        $response = $this->actingAs($ordinaryUser)->postJson("/api/v1/super-admin/users/{$user->id}/deactivate");

        $response->assertStatus(403);
    }
}
