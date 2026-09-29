<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Orders\Models\Order */
final class DevOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'grand_total_minor' => $this->grand_total_minor,
            'currency' => $this->currency,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
