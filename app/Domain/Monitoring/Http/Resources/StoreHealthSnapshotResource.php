<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Snapshot internals (id, store_id) are never exposed (ADR-003); the
 * store is identified by its public_id when loaded.
 *
 * @mixin \App\Domain\Monitoring\Models\StoreHealthSnapshot
 */
final class StoreHealthSnapshotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'store' => $this->whenLoaded('store', fn () => [
                'id' => $this->store->public_id,
                'name' => $this->store->name,
                'slug' => $this->store->slug,
            ]),
            'status' => $this->overall_status->value,
            'checked_at' => $this->created_at->toIso8601String(),
            'checks' => $this->checks,
        ];
    }
}
