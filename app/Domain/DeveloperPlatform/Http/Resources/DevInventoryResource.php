<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Inventory\Models\Inventory */
final class DevInventoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'product_id' => $this->product_id,
            'warehouse_id' => $this->warehouse_id,
            'on_hand' => $this->on_hand,
            'reserved' => $this->reserved,
            'available' => $this->on_hand - $this->reserved,
        ];
    }
}
