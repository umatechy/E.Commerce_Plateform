<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ShipmentItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'order_item_id' => $this->orderItem->id, // internal-but-order-scoped reference the staff UI already has via the Order detail view
            'product_name' => $this->orderItem->product_name_snapshot,
            'quantity' => $this->quantity,
        ];
    }
}
