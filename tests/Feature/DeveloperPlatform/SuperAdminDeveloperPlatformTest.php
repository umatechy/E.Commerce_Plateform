<?php

declare(strict_types=1);

namespace Tests\Feature\DeveloperPlatform;

use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B18 — Super Admin developer-platform oversight, reusing B16's
 * existing platform-global route group unchanged (Module 31 §51).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class SuperAdminDeveloperPlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_see_applications_across_every_store(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        DeveloperApplication::factory()->for($storeA)->create();
        DeveloperApplication::factory()->for($storeB)->create();
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $response = $this->actingAs($superAdmin)->getJson('/api/v1/super-admin/developer/applications');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(2, $response->json('data.total'));
    }

    public function test_super_admin_can_suspend_any_stores_application(): void
    {
        $store = Store::factory()->create();
        $application = DeveloperApplication::factory()->for($store)->create();
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $response = $this->actingAs($superAdmin)->postJson("/api/v1/super-admin/developer/applications/{$application->id}/suspend");

        $response->assertStatus(204);
        $this->assertSame('suspended', $application->fresh()->status->value);
    }

    public function test_non_platform_staff_cannot_reach_super_admin_developer_platform_routes(): void
    {
        $ordinaryUser = User::factory()->create(['platform_role' => null]);

        $response = $this->actingAs($ordinaryUser)->getJson('/api/v1/super-admin/developer/applications');

        $response->assertStatus(403);
    }
}
