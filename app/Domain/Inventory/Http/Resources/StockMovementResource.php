<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type->value,
            'quantity' => $this->quantity,
            'previous_on_hand' => $this->previous_on_hand,
            'new_on_hand' => $this->new_on_hand,
            'reason' => $this->reason,
            'reference_type' => $this->reference_type,
            'actor_id' => $this->actor_id,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
