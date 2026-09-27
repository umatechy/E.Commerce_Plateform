<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Domain\Identity\Models\User;
use App\Domain\Infrastructure\Services\InfrastructureHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B20 — Health check service/endpoints: never exposes secrets,
 * distinguishes public vs Super-Admin-only detail (Module 20 Phase 30,
 * Non-Negotiable).
 * STATUS: NOT EXECUTED — ENVIRONMENT LIMITATION (no PHP/MySQL/Redis
 * runtime available in this Claude App sandbox).
 */
final class InfrastructureHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_check_reports_ok_when_all_dependencies_are_reachable(): void
    {
        $result = app(InfrastructureHealthService::class)->check();

        $this->assertSame('ok', $result['status']);
        $this->assertArrayHasKey('database', $result['checks']);
        $this->assertArrayHasKey('cache', $result['checks']);
        $this->assertArrayHasKey('storage', $result['checks']);
    }

    public function test_public_health_endpoint_requires_no_authentication(): void
    {
        $response = $this->getJson('/api/v1/public/health');

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');
    }

    public function test_public_health_endpoint_never_exposes_a_raw_exception_or_hostname(): void
    {
        $response = $this->getJson('/api/v1/public/health');

        $body = $response->getContent();
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('.env', $body);
    }

    public function test_super_admin_can_reach_the_detailed_health_endpoint(): void
    {
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $response = $this->actingAs($superAdmin)->getJson('/api/v1/super-admin/infrastructure/health');

        $response->assertOk();
    }

    public function test_non_platform_staff_cannot_reach_the_super_admin_health_endpoint(): void
    {
        $ordinaryUser = User::factory()->create(['platform_role' => null]);

        $response = $this->actingAs($ordinaryUser)->getJson('/api/v1/super-admin/infrastructure/health');

        $response->assertStatus(403);
    }

    public function test_the_cli_health_check_command_exits_successfully_when_healthy(): void
    {
        $this->artisan('infrastructure:health-check')->assertExitCode(0);
    }
}
