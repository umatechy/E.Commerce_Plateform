<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ShippingZoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'country' => $this->country,
            'province' => $this->province,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
        ];
    }
}
