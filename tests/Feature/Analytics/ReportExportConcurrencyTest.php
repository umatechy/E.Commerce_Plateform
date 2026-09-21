<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Jobs\GenerateReportExportJob;
use App\Domain\Analytics\Models\ReportExport;
use App\Domain\Analytics\Models\ReportType;
use App\Domain\Analytics\Services\DateRangeResolver;
use App\Domain\Analytics\Services\ReportService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase B12 — this milestone's exact "two workers generate same
 * report" / duplicate-export scenario (Step 52). Simulated
 * sequentially (no real concurrent process available in this
 * environment — see every prior phase's identical, honestly-labeled
 * precedent). Relies on ReportExport's own idempotency-key check and
 * the job's own "already processed" guard — no new concurrency
 * mechanism was built for B12.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 * This test has NOT been run under genuine parallel load; that
 * verification is explicitly deferred to VS Code/CI.
 */
final class ReportExportConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_overlapping_job_dispatches_for_the_same_export_do_not_double_process(): void
    {
        Storage::fake('local');
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $export = ReportExport::query()->create(['report_type' => ReportType::Inventory, 'filters' => [], 'status' => 'pending', 'idempotency_key' => 'conc-1']);

        $job = new GenerateReportExportJob($export->id);
        $job->handle(app(TenantContext::class), app(ReportService::class), app(DateRangeResolver::class));
        $firstPath = $export->fresh()->file_path;

        // A second, overlapping dispatch (e.g. a duplicate queue
        // delivery) arrives — the export is now Completed, so the
        // job's own already-processed guard makes this a safe no-op.
        $job->handle(app(TenantContext::class), app(ReportService::class), app(DateRangeResolver::class));

        $this->assertSame('completed', $export->fresh()->status->value);
        $this->assertSame($firstPath, $export->fresh()->file_path);
    }
}
