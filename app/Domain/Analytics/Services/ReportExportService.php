<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Jobs\GenerateReportExportJob;
use App\Domain\Analytics\Models\ReportExport;
use App\Domain\Analytics\Models\ReportExportStatus;
use App\Domain\Analytics\Models\ReportType;

/**
 * Module 22 §15/§17 "Exports / Export Idempotency". The ONLY code
 * path that creates a ReportExport record — mirrors every other
 * domain service's "service-only writes" pattern.
 */
final class ReportExportService
{
    public function requestExport(ReportType $reportType, array $filters, ?int $requestedByUserId, string $idempotencyKey): ReportExport
    {
        if ($existing = ReportExport::query()->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing; // Module 22 §17: a double-click/browser-retry never creates a second export
        }

        $export = ReportExport::query()->create([
            'requested_by_user_id' => $requestedByUserId,
            'report_type' => $reportType,
            'filters' => $filters,
            'status' => ReportExportStatus::Pending,
            'idempotency_key' => $idempotencyKey,
        ]);

        GenerateReportExportJob::dispatch($export->id);

        return $export;
    }
}
