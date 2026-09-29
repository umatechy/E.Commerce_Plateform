<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Jobs\GenerateReportExportJob;
use App\Domain\Analytics\Models\ReportExport;
use App\Domain\Analytics\Models\ReportType;
use App\Domain\Analytics\Services\DateRangeResolver;
use App\Domain\Analytics\Services\ReportExportService;
use App\Domain\Analytics\Services\ReportService;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase B12 — Export idempotency, queued generation, tenant isolation,
 * signed-URL download (Module 22 §15-17).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Store $store): User
    {
        $role = $this->systemRole($store, 'owner');
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_duplicate_export_request_returns_the_same_record(): void
    {
        Bus::fake();
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        $first = app(ReportExportService::class)->requestExport(ReportType::Sales, [], null, 'same-key');
        $second = app(ReportExportService::class)->requestExport(ReportType::Sales, [], null, 'same-key');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ReportExport::query()->count());
    }

    public function test_export_generation_produces_a_completed_csv(): void
    {
        Storage::fake('local');
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        Order::factory()->for($store)->create(['grand_total_minor' => 5000, 'status' => OrderStatus::Confirmed]);
        $export = ReportExport::query()->create(['report_type' => ReportType::Sales, 'filters' => ['date_filter' => 'today'], 'status' => 'pending', 'idempotency_key' => 'k1']);

        (new GenerateReportExportJob($export->id))->handle(app(TenantContext::class), app(ReportService::class), app(DateRangeResolver::class));

        $this->assertSame('completed', $export->fresh()->status->value);
        Storage::disk('local')->assertExists($export->fresh()->file_path);
    }

    public function test_export_with_invalid_filters_is_marked_failed_not_left_pending(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $export = ReportExport::query()->create(['report_type' => ReportType::Sales, 'filters' => ['date_filter' => 'custom', 'start' => null, 'end' => null], 'status' => 'pending', 'idempotency_key' => 'k2']);

        (new GenerateReportExportJob($export->id))->handle(app(TenantContext::class), app(ReportService::class), app(DateRangeResolver::class));

        $this->assertSame('failed', $export->fresh()->status->value);
    }

    public function test_store_a_cannot_view_store_bs_export(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        app(TenantContext::class)->resolveToStore($storeB->id);
        $exportB = ReportExport::query()->create(['report_type' => ReportType::Sales, 'filters' => [], 'status' => 'pending', 'idempotency_key' => 'k3']);

        $this->actingAs($ownerA)->getJson("/api/v1/exports/{$exportB->id}")->assertStatus(404);
    }

    public function test_non_downloadable_export_returns_no_download_url(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        app(TenantContext::class)->resolveToStore($store->id);
        $export = ReportExport::query()->create(['report_type' => ReportType::Sales, 'filters' => [], 'status' => 'pending', 'idempotency_key' => 'k4']);

        $response = $this->actingAs($owner)->getJson("/api/v1/exports/{$export->id}");

        $response->assertOk();
        $response->assertJsonPath('data.download_url', null);
    }

    public function test_a_completed_export_download_link_actually_works(): void
    {
        Storage::fake('local');
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        Order::factory()->for($store)->create(['grand_total_minor' => 5000, 'status' => OrderStatus::Confirmed]);
        $export = ReportExport::query()->create(['report_type' => ReportType::Sales, 'filters' => ['date_filter' => 'today'], 'status' => 'pending', 'idempotency_key' => 'k5']);
        (new GenerateReportExportJob($export->id))->handle(app(TenantContext::class), app(ReportService::class), app(DateRangeResolver::class));

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('report-exports.download', now()->addMinutes(5), ['exportPublicId' => $export->fresh()->public_id]);

        $response = $this->get($url);

        $response->assertOk();
    }

    public function test_a_tampered_download_link_is_rejected(): void
    {
        Storage::fake('local');
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $export = ReportExport::query()->create(['report_type' => ReportType::Sales, 'filters' => [], 'status' => 'completed', 'file_path' => 'reports/fake.csv', 'idempotency_key' => 'k6', 'expires_at' => now()->addDay()]);

        $response = $this->get("/api/v1/public/report-exports/{$export->public_id}/download?signature=forged&expires=9999999999");

        $response->assertStatus(403);
    }
}
