<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B16 — Store/Tenant Management: platform-wide search/detail,
 * reusing each domain's own authoritative data (Module 30 §9).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class SuperAdminStoreManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_staff_can_search_stores_by_name(): void
    {
        Store::factory()->create(['name' => 'Findable Shop']);
        Store::factory()->create(['name' => 'Other Shop']);
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $response = $this->actingAs($superAdmin)->getJson('/api/v1/super-admin/stores?search=Findable');

        $response->assertOk();
        $this->assertSame(1, $response->json('data.total'));
    }

    public function test_store_detail_includes_subscription_and_domain_snapshot(): void
    {
        $store = Store::factory()->create();
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $response = $this->actingAs($superAdmin)->getJson("/api/v1/super-admin/stores/{$store->id}");

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['store', 'subscription_status', 'primary_domain', 'low_stock_products']]);
    }

    public function test_non_platform_staff_cannot_list_stores(): void
    {
        $ordinaryUser = User::factory()->create(['platform_role' => null]);

        $response = $this->actingAs($ordinaryUser)->getJson('/api/v1/super-admin/stores');

        $response->assertStatus(403);
    }
}
