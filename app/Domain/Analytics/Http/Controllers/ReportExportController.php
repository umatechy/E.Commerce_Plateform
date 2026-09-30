<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Http\Controllers;

use App\Domain\Analytics\Http\Requests\RequestExportRequest;
use App\Domain\Analytics\Http\Resources\ReportExportResource;
use App\Domain\Analytics\Models\ReportExport;
use App\Domain\Analytics\Models\ReportType;
use App\Domain\Analytics\Policies\AnalyticsPolicy;
use App\Domain\Analytics\Services\ReportExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Module 22 §15-17 "Exports / Export Security / Export Idempotency".
 * Generation is always queued (GenerateReportExportJob) — this
 * controller never generates a file synchronously inside the request.
 */
final class ReportExportController
{
    public function store(RequestExportRequest $request, ReportExportService $exports): JsonResponse
    {
        abort_unless(app(AnalyticsPolicy::class)->export($request->user()), 403);

        $export = $exports->requestExport(
            ReportType::from($request->string('report_type')->toString()),
            $request->only(['date_filter', 'start', 'end']),
            $request->user()->id,
            $request->string('idempotency_key')->toString(),
        );

        return (new ReportExportResource($export))->response()->setStatusCode($export->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, ReportExport $export): ReportExportResource
    {
        abort_unless(app(AnalyticsPolicy::class)->downloadExport($request->user(), $export), 404);

        return new ReportExportResource($export);
    }

    /**
     * Module 22 §16 "Export Security" — reached only via a
     * Laravel-signed, time-limited URL (`signed` middleware verifies
     * the signature/expiry before this method ever runs); no
     * additional Sanctum session is required to follow the link
     * (matching how a real download link is typically opened/shared),
     * but the export itself is still resolved by its own tenant-scoped
     * `public_id` — a tampered or expired link is rejected by the
     * `signed` middleware before reaching here at all.
     */
    public function download(string $exportPublicId)
    {
        $export = ReportExport::query()->withoutTenantScope()->where('public_id', $exportPublicId)->firstOrFail();

        abort_unless($export->isDownloadable(), 404);

        return Storage::disk('local')->download($export->file_path, "{$export->report_type->value}-report.csv");
    }
}
