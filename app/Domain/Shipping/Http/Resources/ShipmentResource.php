<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 13 §74-75 "Customer Tracking / Admin Shipping View" — this
 * exact field list is what BOTH the customer-facing and staff-facing
 * endpoints return. Never includes carrier credentials or internal
 * IDs — always `public_id` for API addressing.
 */
final class ShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'carrier' => $this->carrier,
            'status' => $this->status->value,
            'tracking_number' => $this->tracking_number,
            'shipping_cost_minor' => $this->shipping_cost_minor,
            'currency' => $this->currency,
            'estimated_delivery_at' => $this->estimated_delivery_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'items' => ShipmentItemResource::collection($this->whenLoaded('items')),
            'tracking_events' => ShipmentTrackingEventResource::collection($this->whenLoaded('trackingEvents')),
        ];
    }
}
