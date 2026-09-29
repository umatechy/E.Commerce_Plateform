<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Inventory\Models\StockReservation */
final class StockReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'inventory_id' => $this->inventory_id,
            'quantity' => $this->quantity,
            'status' => $this->status->value,
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }
}
