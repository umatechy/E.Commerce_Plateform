<?php

declare(strict_types=1);

namespace App\Domain\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'product_name' => $this->product_name_snapshot,
            'sku' => $this->sku_snapshot,
            'variant' => $this->variant_snapshot,
            'quantity' => $this->quantity,
            'unit_price_minor' => $this->unit_price_minor,
            'discount_minor' => $this->discount_minor,
            'tax_minor' => $this->tax_minor,
            'line_total_minor' => $this->line_total_minor,
            'fulfillment_status' => $this->fulfillment_status->value,
        ];
    }
}
