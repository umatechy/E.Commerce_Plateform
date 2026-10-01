<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\DataProtection\Models\BackupRestoreJob */
final class BackupRestoreJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'backup_id' => $this->backup->public_id,
            'mode' => $this->mode->value,
            'status' => $this->status->value,
            'reference' => $this->reference,
            'failure_reason' => $this->failure_reason,
            'report' => $this->report,
            'duration_ms' => $this->duration_ms,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
