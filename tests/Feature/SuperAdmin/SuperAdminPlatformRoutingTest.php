<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B16 — regression test for the exact critical bug found and
 * fixed this milestone (see docs/development/b16-inspection-findings.md
 * "Critical Bug Found"): platform-global Super Admin routes (no target
 * store) must never corrupt TenantContext with a fabricated store id.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class SuperAdminPlatformRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_global_package_route_resolves_platform_context_not_a_fabricated_store(): void
    {
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $response = $this->actingAs($superAdmin)->getJson('/api/v1/super-admin/packages');

        $response->assertOk();
        // The pre-fix behavior would have called
        // TenantContext::markImpersonation($user->id, 0) — this
        // assertion confirms the request completed via the PLATFORM
        // path (resolveToPlatform()) instead, by checking the context
        // left behind is platform mode, never "store 0".
        $this->assertTrue(app(TenantContext::class)->isPlatform());
    }

    public function test_platform_global_theme_route_never_requires_a_store_route_parameter(): void
    {
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $response = $this->actingAs($superAdmin)->getJson('/api/v1/super-admin/themes');

        $response->assertOk();
    }

    public function test_non_platform_staff_cannot_reach_platform_global_routes(): void
    {
        $ordinaryUser = User::factory()->create(['platform_role' => null]);

        $response = $this->actingAs($ordinaryUser)->getJson('/api/v1/super-admin/packages');

        $response->assertStatus(403);
    }

    public function test_platform_action_is_audit_logged_with_the_real_route_never_a_fabricated_store_id(): void
    {
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        \Illuminate\Support\Facades\Log::shouldReceive('channel')->with('audit')->andReturnSelf();
        \Illuminate\Support\Facades\Log::shouldReceive('info')
            ->once()
            ->with('super_admin.platform_action', \Mockery::on(function (array $context) {
                return ! array_key_exists('target_store_id', $context); // the bug's own symptom: a bogus target_store_id must never appear here
            }));

        $this->actingAs($superAdmin)->getJson('/api/v1/super-admin/packages');
    }
}
