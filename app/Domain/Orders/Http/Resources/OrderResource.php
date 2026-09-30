<?php

declare(strict_types=1);

namespace App\Domain\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Orders\Models\Order */
final class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'payment_status' => $this->payment_status->value,
            'fulfillment_status' => $this->fulfillment_status->value,
            'source' => $this->source->value,
            'currency' => $this->currency,
            'subtotal_minor' => $this->subtotal_minor,
            'discount_total_minor' => $this->discount_total_minor,
            'tax_total_minor' => $this->tax_total_minor,
            'shipping_total_minor' => $this->shipping_total_minor,
            'grand_total_minor' => $this->grand_total_minor,
            'is_guest_order' => $this->isGuestOrder(),
            'guest_name' => $this->when($this->isGuestOrder(), $this->guest_name),
            'notes' => $this->notes,
            'cancellation_reason' => $this->cancellation_reason?->value,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
