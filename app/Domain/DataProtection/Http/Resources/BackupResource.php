<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Module 23 "Important Security Principle" — Non-Negotiable: NEVER exposes storage_path/storage_disk (internal, opaque) or manifest contents that could reveal infrastructure details. */
final class BackupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'scope' => $this->scope->value,
            'status' => $this->status->value,
            'initiated_by' => $this->initiated_by->value,
            'size_bytes' => $this->size_bytes,
            'is_encrypted' => $this->is_encrypted,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
