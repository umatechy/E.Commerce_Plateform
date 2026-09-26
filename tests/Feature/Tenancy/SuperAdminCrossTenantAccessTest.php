<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Models\Store;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-001 Layer 7 — Super Admin cross-tenant access must be explicit,
 * permission-checked, and audit-logged; never an accidental side-effect
 * of an unscoped query.
 *
 * STATUS: NOT EXECUTED — CLAUDE APP ENVIRONMENT LIMITATION (see
 * TenantIsolationTest docblock for the same note; applies to this file
 * too).
 */
final class SuperAdminCrossTenantAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_platform_user_cannot_access_super_admin_impersonation_route(): void
    {
        $store = Store::factory()->create();
        $ordinaryUser = User::factory()->create(['platform_role' => null]);

        $response = $this->actingAs($ordinaryUser)
            ->getJson("/api/v1/super-admin/stores/{$store->id}/impersonate?reason=support+ticket+%23123");

        $response->assertStatus(403);
    }

    public function test_platform_staff_impersonation_is_audit_logged(): void
    {
        $store = Store::factory()->create();
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        // Phase B16 hardening (see docs/development/b16-inspection-findings.md
        // "Second Finding"/"Third Finding"): impersonation now writes
        // TWO audit entries — the pre-existing generic middleware-level
        // one (proves WHO accessed the surface) AND a new action-specific
        // one carrying the required `reason` (proves WHY) — neither
        // replaces the other.
        \Illuminate\Support\Facades\Log::shouldReceive('channel')
            ->with('audit')
            ->andReturnSelf();
        \Illuminate\Support\Facades\Log::shouldReceive('info')
            ->once()
            ->with('super_admin.impersonation.started', \Mockery::type('array'));
        \Illuminate\Support\Facades\Log::shouldReceive('info')
            ->once()
            ->with('super_admin.store.impersonated', \Mockery::type('array'));

        $this->actingAs($superAdmin)
            ->getJson("/api/v1/super-admin/stores/{$store->id}/impersonate?reason=support+ticket+%23123");
    }

    public function test_impersonation_without_a_reason_is_rejected(): void
    {
        // Regression test for the exact hardening in
        // docs/development/b16-inspection-findings.md "Second Finding" —
        // Module 30 §27 requires an explicit reason for impersonation.
        $store = Store::factory()->create();
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $response = $this->actingAs($superAdmin)->getJson("/api/v1/super-admin/stores/{$store->id}/impersonate");

        $response->assertStatus(422);
    }
}
