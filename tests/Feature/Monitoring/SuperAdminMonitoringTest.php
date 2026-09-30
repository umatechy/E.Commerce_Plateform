<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Domain\DeveloperPlatform\Models\ApiRequestLog;
use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use App\Domain\DeveloperPlatform\Services\ApiKeyService;
use App\Domain\Events\Models\OutboxEvent;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Identity\Models\User;
use App\Domain\Monitoring\Models\StoreHealthSnapshot;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase B21 — Module 24 Super Admin monitoring: platform-wide store
 * health, the ADR-004 outbox backlog and ADR-005 API version usage.
 */
final class SuperAdminMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['platform_role' => 'support_agent']);
    }

    public function test_the_overview_lists_each_stores_latest_snapshot_most_severe_first(): void
    {
        $healthy = Store::factory()->create(['name' => 'Healthy Store']);
        $failing = Store::factory()->create(['name' => 'Failing Store']);
        StoreHealthSnapshot::query()->create(['store_id' => $healthy->id, 'overall_status' => 'critical', 'checks' => []]);
        StoreHealthSnapshot::query()->create(['store_id' => $healthy->id, 'overall_status' => 'ok', 'checks' => []]); // newer: recovered
        StoreHealthSnapshot::query()->create(['store_id' => $failing->id, 'overall_status' => 'critical', 'checks' => []]);

        $response = $this->actingAs($this->superAdmin())->getJson('/api/v1/super-admin/store-health');

        $response->assertOk();
        $this->assertSame(2, $response->json('data.total'));
        $this->assertSame('Failing Store', $response->json('data.data.0.store.name'));
        $this->assertSame('critical', $response->json('data.data.0.status'));
        $this->assertSame('ok', $response->json('data.data.1.status'));
        $this->assertSame($failing->public_id, $response->json('data.data.0.store.id'));
    }

    public function test_the_overview_can_be_filtered_by_status(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        StoreHealthSnapshot::query()->create(['store_id' => $storeA->id, 'overall_status' => 'warning', 'checks' => []]);
        StoreHealthSnapshot::query()->create(['store_id' => $storeB->id, 'overall_status' => 'ok', 'checks' => []]);

        $response = $this->actingAs($this->superAdmin())->getJson('/api/v1/super-admin/store-health?status=warning');

        $response->assertOk();
        $this->assertSame(1, $response->json('data.total'));
        $this->actingAs($this->superAdmin())->getJson('/api/v1/super-admin/store-health?status=bogus')->assertStatus(422);
    }

    public function test_a_super_admin_can_evaluate_one_stores_health_live(): void
    {
        $store = Store::factory()->create();

        $response = $this->actingAs($this->superAdmin())->getJson("/api/v1/super-admin/stores/{$store->id}/health");

        $response->assertOk()->assertJsonPath('data.checks.0.key', 'setup');
    }

    public function test_the_outbox_backlog_reports_failed_events_as_critical(): void
    {
        $store = Store::factory()->create();
        DB::transaction(fn () => app(RecordsOutboxEvents::class)->recordEventFor($store->id, 'test.event', [], 'monitoring-outbox-1'));
        DB::transaction(fn () => app(RecordsOutboxEvents::class)->recordEventFor($store->id, 'test.event', [], 'monitoring-outbox-2'));
        OutboxEvent::query()->withoutTenantScope()->where('idempotency_key', 'monitoring-outbox-2')->update(['status' => 'failed']);

        $response = $this->actingAs($this->superAdmin())->getJson('/api/v1/super-admin/monitoring/outbox');

        $response->assertOk()
            ->assertJsonPath('data.status', 'critical')
            ->assertJsonPath('data.by_status.pending', 1)
            ->assertJsonPath('data.by_status.failed', 1)
            ->assertJsonPath('data.failed_recently', 1)
            ->assertJsonPath('data.failed_jobs', 0);
    }

    public function test_api_usage_is_grouped_by_version(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $application = DeveloperApplication::factory()->for($store)->create();
        $key = app(ApiKeyService::class)->issue($application, ['products:read'])['key'];
        foreach ([200, 200, 503] as $status) {
            ApiRequestLog::query()->create([
                'store_id' => $store->id, 'api_key_id' => $key->id, 'method' => 'GET',
                'endpoint' => 'api/dev/v1/products', 'status_code' => $status, 'duration_ms' => 12,
            ]);
        }

        $response = $this->actingAs($this->superAdmin())->getJson('/api/v1/super-admin/monitoring/api-usage?days=7');

        $response->assertOk()
            ->assertJsonPath('data.days', 7)
            ->assertJsonPath('data.versions.0.version', 'v1')
            ->assertJsonPath('data.versions.0.requests', 3)
            ->assertJsonPath('data.versions.0.stores', 1)
            ->assertJsonPath('data.versions.0.server_errors', 1);
    }

    public function test_non_platform_staff_cannot_reach_any_monitoring_route(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->create(['platform_role' => null]);

        foreach (['/api/v1/super-admin/store-health', '/api/v1/super-admin/monitoring/outbox', '/api/v1/super-admin/monitoring/api-usage', "/api/v1/super-admin/stores/{$store->id}/health"] as $uri) {
            $this->actingAs($user)->getJson($uri)->assertForbidden();
        }
    }
}
