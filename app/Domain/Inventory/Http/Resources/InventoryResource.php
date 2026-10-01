<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 08 §74 "Cost Data Security" — this resource carries no cost
 * field at all (B4 does not model inventory cost — see
 * docs/development/b4-inspection-findings.md "Scope Decision"), so
 * there is nothing to accidentally leak here.
 *
 * @mixin \App\Domain\Inventory\Models\Inventory
 */
final class InventoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            // Stock kept per variant has no product of its own: name the variant's.
            // (`when`, not `whenLoaded`: the latter answers null for a null relation without asking.)
            'product' => $this->when($this->relationLoaded('product'), function () {
                $product = $this->product ?? ($this->relationLoaded('variant') ? $this->variant?->product : null);

                return $product === null ? null : ['id' => $product->public_id, 'name' => $product->name, 'sku' => $product->sku];
            }),
            'variant' => $this->whenLoaded('variant', fn () => $this->variant === null ? null : ['id' => $this->variant->public_id, 'sku' => $this->variant->sku, 'option_values' => $this->variant->option_values]),
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'on_hand' => $this->on_hand,
            'reserved' => $this->reserved,
            'available' => $this->available(),
            'incoming' => $this->incoming,
            'reorder_point' => $this->reorder_point,
            'reorder_quantity' => $this->reorder_quantity,
            'is_low_stock' => $this->isLowStock(),
            'is_out_of_stock' => $this->isOutOfStock(),
        ];
    }
}
