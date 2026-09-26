<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B17 — Super Admin platform-settings administration, reusing
 * B16's existing platform-global route group unchanged (Module 33
 * §18/§40).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class SuperAdminSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_staff_can_view_and_update_platform_settings(): void
    {
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $update = $this->actingAs($superAdmin)->putJson('/api/v1/super-admin/settings/platform.maintenance_mode', ['value' => true]);
        $update->assertOk();

        $index = $this->actingAs($superAdmin)->getJson('/api/v1/super-admin/settings');
        $index->assertOk();
    }

    public function test_non_platform_staff_cannot_update_platform_settings(): void
    {
        $ordinaryUser = User::factory()->create(['platform_role' => null]);

        $response = $this->actingAs($ordinaryUser)->putJson('/api/v1/super-admin/settings/platform.maintenance_mode', ['value' => true]);

        $response->assertStatus(403);
    }

    public function test_platform_setting_update_is_audit_logged(): void
    {
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        \Illuminate\Support\Facades\Log::shouldReceive('channel')->with('audit')->andReturnSelf();
        \Illuminate\Support\Facades\Log::shouldReceive('info')->with('super_admin.platform_action', \Mockery::type('array'));
        \Illuminate\Support\Facades\Log::shouldReceive('info')
            ->once()
            ->with('super_admin.setting.updated', \Mockery::on(fn (array $c) => $c['key'] === 'platform.maintenance_mode'));

        $this->actingAs($superAdmin)->putJson('/api/v1/super-admin/settings/platform.maintenance_mode', ['value' => true]);
    }
}
