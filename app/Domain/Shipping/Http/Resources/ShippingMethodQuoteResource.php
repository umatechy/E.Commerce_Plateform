<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Module 13 §82 "Shipping Quote API" — customer-facing method + server-calculated cost, no internal rate/zone IDs exposed. */
final class ShippingMethodQuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['method']->id,
            'name' => $this->resource['method']->name,
            'type' => $this->resource['method']->type->value,
            'cost_minor' => $this->resource['cost_minor'],
        ];
    }
}
