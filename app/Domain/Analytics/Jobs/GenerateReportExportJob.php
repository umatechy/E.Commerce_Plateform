<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Jobs;

use App\Domain\Analytics\Models\ReportExport;
use App\Domain\Analytics\Models\ReportExportStatus;
use App\Domain\Analytics\Models\ReportType;
use App\Domain\Analytics\Services\DateRangeResolver;
use App\Domain\Analytics\Services\ReportService;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Module 22 §15 "Exports" (async, queued — never generated synchronously
 * inside an HTTP request) + §16 "Export Security" (stored under
 * storage/app/private, tenant-scoped, never a predictable public
 * path). Tenant context is resolved from the ReportExport row's OWN
 * store_id (loaded by this job's own database lookup) — never trusted
 * from an arbitrary job payload field, mirroring every queued job
 * since Phase B10/B11.
 */
final class GenerateReportExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $reportExportId) {}

    public function handle(TenantContext $context, ReportService $reports, DateRangeResolver $dateRanges): void
    {
        $export = ReportExport::query()->withoutTenantScope()->findOrFail($this->reportExportId);
        $context->resolveToStore($export->store_id);
        $export = ReportExport::query()->findOrFail($this->reportExportId);

        if ($export->status !== ReportExportStatus::Pending) {
            return; // idempotent-replay guard — mirrors every other queued job's terminal/already-processed check since Phase B10
        }

        $export->update(['status' => ReportExportStatus::Processing]);

        try {
            [$start, $end] = $dateRanges->resolve(
                $export->filters['date_filter'] ?? 'this_month',
                $export->filters['start'] ?? null,
                $export->filters['end'] ?? null,
            );

            $rows = $this->generateRows($export->report_type, $reports, $start, $end);
            $path = $this->writeCsv($export, $rows);

            $export->update([
                'status' => ReportExportStatus::Completed,
                'file_path' => $path,
                'row_count' => count($rows),
                'expires_at' => now()->addDays(7), // Module 22 §44 "Export Security" — retained only for a bounded window, a documented default (no exact retention period given by the specification)
            ]);
        } catch (\Throwable $e) {
            $export->update(['status' => ReportExportStatus::Failed, 'failure_reason' => $e->getMessage()]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        ReportExport::query()->withoutTenantScope()->whereKey($this->reportExportId)
            ->update(['status' => ReportExportStatus::Failed->value, 'failure_reason' => $exception->getMessage()]);
    }

    /** @return list<array<string, mixed>> */
    private function generateRows(ReportType $type, ReportService $reports, \Illuminate\Support\Carbon $start, \Illuminate\Support\Carbon $end): array
    {
        return match ($type) {
            ReportType::Sales => $reports->salesReport($start, $end),
            ReportType::Customers => [$reports->customersReport($start, $end)],
            ReportType::Payments => $reports->paymentsReport($start, $end),
            ReportType::Shipping => $reports->shippingReport($start, $end),
            ReportType::Promotions => $reports->promotionsReport($start, $end),
            ReportType::Marketing => $reports->marketingReport($start, $end),
            ReportType::Notifications => $reports->notificationsReport($start, $end),
            ReportType::Inventory => [$reports->inventoryReport()],
            ReportType::Products => $reports->productsReport($start, $end, perPage: 1000)->items(),
        };
    }

    /** @param list<array<string, mixed>> $rows */
    private function writeCsv(ReportExport $export, array $rows): string
    {
        $path = "reports/{$export->store_id}/{$export->public_id}.csv";
        $handle = fopen('php://temp', 'w+');

        if ($rows !== []) {
            fputcsv($handle, array_keys((array) $rows[0]));
            foreach ($rows as $row) {
                fputcsv($handle, (array) $row);
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        Storage::disk('local')->put($path, $csv);

        return $path;
    }
}
