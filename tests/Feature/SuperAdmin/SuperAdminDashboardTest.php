<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B16 — Platform Dashboard: aggregates across ALL stores, never
 * one tenant's own dashboard (Module 30 §8).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class SuperAdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_aggregates_orders_across_every_store(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($storeA->id);
        Order::factory()->for($storeA)->create(['grand_total_minor' => 1000, 'status' => OrderStatus::Confirmed]);
        app(TenantContext::class)->resolveToStore($storeB->id);
        Order::factory()->for($storeB)->create(['grand_total_minor' => 2000, 'status' => OrderStatus::Confirmed]);
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $response = $this->actingAs($superAdmin)->getJson('/api/v1/super-admin/dashboard');

        $response->assertOk();
        $response->assertJsonPath('data.orders.total_last_30_days', 2);
        $response->assertJsonPath('data.orders.revenue_minor_last_30_days', 3000);
    }

    public function test_dashboard_reports_total_store_count(): void
    {
        Store::factory()->count(3)->create();
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $response = $this->actingAs($superAdmin)->getJson('/api/v1/super-admin/dashboard');

        $response->assertOk();
        $response->assertJsonPath('data.stores.total', 3);
    }

    public function test_non_platform_staff_cannot_view_the_platform_dashboard(): void
    {
        $ordinaryUser = User::factory()->create(['platform_role' => null]);

        $response = $this->actingAs($ordinaryUser)->getJson('/api/v1/super-admin/dashboard');

        $response->assertStatus(403);
    }
}
