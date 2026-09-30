<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Analytics\Models\ReportExport */
final class ReportExportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'report_type' => $this->report_type->value,
            'status' => $this->status->value,
            'row_count' => $this->row_count,
            'failure_reason' => $this->failure_reason,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'download_url' => $this->isDownloadable()
                ? \Illuminate\Support\Facades\URL::temporarySignedRoute('report-exports.download', $this->expires_at, ['exportPublicId' => $this->public_id])
                : null,
        ];
    }
}
