<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Shipping\Models\ShipmentTrackingEvent */
final class ShipmentTrackingEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status->value,
            'description' => $this->description,
            'location' => $this->location,
            'source' => $this->source,
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
