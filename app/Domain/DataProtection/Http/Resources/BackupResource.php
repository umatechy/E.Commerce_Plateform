<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 23 "Important Security Principle" — Non-Negotiable: NEVER exposes storage_path/storage_disk (internal, opaque) or manifest contents that could reveal infrastructure details.
 *
 * @mixin \App\Domain\DataProtection\Models\Backup
 */
final class BackupResource extends JsonResource
{
    /**
     * The technical reason can name a database host, an account or a
     * storage location. Platform staff see it; a store sees that the
     * backup failed and nothing about the infrastructure.
     */
    private function failureReasonFor(Request $request): ?string
    {
        if ($this->failure_reason === null) {
            return null;
        }

        return $request->user()?->isPlatformStaff() === true && $request->is('api/v1/super-admin/*')
            ? $this->failure_reason
            : 'The backup did not complete. The platform team has been notified.';
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'scope' => $this->scope->value,
            'status' => $this->status->value,
            'initiated_by' => $this->initiated_by->value,
            'retention_tier' => $this->retention_tier->value,
            'size_bytes' => $this->size_bytes,
            'checksum_sha256' => $this->checksum_sha256,
            'is_encrypted' => $this->is_encrypted,
            'compression' => $this->compression,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'duration_ms' => $this->manifest['duration_ms'] ?? null,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'failure_reason' => $this->failureReasonFor($request),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
