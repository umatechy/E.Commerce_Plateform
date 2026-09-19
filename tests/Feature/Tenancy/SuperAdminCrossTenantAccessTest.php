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
            ->getJson("/api/v1/super-admin/stores/{$store->id}/impersonate");

        $response->assertStatus(403);
    }

    public function test_platform_staff_impersonation_is_audit_logged(): void
    {
        $store = Store::factory()->create();
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        \Illuminate\Support\Facades\Log::shouldReceive('channel')
            ->with('audit')
            ->andReturnSelf();
        \Illuminate\Support\Facades\Log::shouldReceive('info')
            ->once()
            ->with('super_admin.impersonation.started', \Mockery::type('array'));

        $this->actingAs($superAdmin)
            ->getJson("/api/v1/super-admin/stores/{$store->id}/impersonate");
    }
}
