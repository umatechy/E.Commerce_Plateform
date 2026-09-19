<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ShippingRateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shipping_zone_id' => $this->shipping_zone_id,
            'shipping_method_id' => $this->shipping_method_id,
            'currency' => $this->currency,
            'base_cost_minor' => $this->base_cost_minor,
            'per_unit_cost_minor' => $this->per_unit_cost_minor,
            'unit_threshold' => $this->unit_threshold,
        ];
    }
}
