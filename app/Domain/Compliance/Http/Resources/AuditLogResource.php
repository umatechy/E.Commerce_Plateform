<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Internal ids (actor_id, subject_id, store_id) are never exposed
 * (ADR-003); the snapshots taken at write time are.
 *
 * @mixin \App\Domain\Compliance\Models\AuditLog
 */
final class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'sequence' => $this->sequence,
            'action' => $this->action,
            'actor' => [
                'type' => $this->actor_type->value,
                'id' => $this->actor_public_id,
                'label' => $this->actor_label,
            ],
            'impersonated_by' => $this->impersonator_label,
            'surface' => $this->surface->value,
            'subject' => $this->subject_type !== null ? ['type' => $this->subject_type, 'id' => $this->subject_public_id] : null,
            'context' => $this->contextData(),
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'request_id' => $this->request_id,
            'store' => $this->whenLoaded('store', fn () => $this->store !== null ? ['id' => $this->store->public_id, 'name' => $this->store->name] : null),
            'hash' => $this->hash,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
