<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Domain\Identity\Models\User;
use App\Domain\Theme\Models\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B16 — Theme Management: platform catalog CRUD, mirrors Package
 * exactly (Module 17 §7/Module 30 §21).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class SuperAdminThemeManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_staff_can_create_a_new_system_theme(): void
    {
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $response = $this->actingAs($superAdmin)->postJson('/api/v1/super-admin/themes', [
            'key' => 'seasonal-2027', 'name' => 'Seasonal 2027', 'version' => '1.0.0',
        ]);

        $response->assertCreated();
    }

    public function test_duplicate_theme_key_is_rejected(): void
    {
        Theme::query()->create(['key' => 'default', 'name' => 'Default', 'version' => '1.0.0', 'status' => 'active']);
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $response = $this->actingAs($superAdmin)->postJson('/api/v1/super-admin/themes', [
            'key' => 'default', 'name' => 'Duplicate', 'version' => '1.0.0',
        ]);

        $response->assertStatus(422);
    }

    public function test_non_platform_staff_cannot_manage_themes(): void
    {
        $ordinaryUser = User::factory()->create(['platform_role' => null]);

        $response = $this->actingAs($ordinaryUser)->postJson('/api/v1/super-admin/themes', [
            'key' => 'x', 'name' => 'x', 'version' => '1.0.0',
        ]);

        $response->assertStatus(403);
    }
}
