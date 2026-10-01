<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Inventory\Models\Warehouse */
final class WarehouseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'internal_id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'address' => $this->address,
            'contact' => $this->contact,
            'status' => $this->status->value,
            'is_default' => $this->is_default,
            'fulfillment_priority' => $this->fulfillment_priority,
        ];
    }
}
