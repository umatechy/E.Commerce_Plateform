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
 *
 * @mixin \App\Domain\Shipping\Models\Shipment
 */
final class ShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'order' => $this->whenLoaded('order', fn () => $this->order === null ? null : ['id' => $this->order->public_id, 'order_number' => $this->order->order_number]),
            'carrier' => $this->carrier,
            'status' => $this->status->value,
            'tracking_number' => $this->tracking_number,
            'shipping_cost_minor' => $this->shipping_cost_minor,
            'currency' => $this->currency,
            'estimated_delivery_at' => $this->estimated_delivery_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            // Phase B49: who carries it, the booking, cash on delivery, the label, and what can be done now.
            'direction' => $this->direction,
            'return_id' => $this->whenLoaded('returnRequest', fn () => $this->returnRequest?->public_id),
            'carrier_label' => app(\App\Domain\Shipping\Services\ShippingLabelService::class)->carrierName($this->resource),
            'carrier_name' => $this->carrier_name,
            'carrier_reference' => $this->carrier_reference,
            'tracking_url' => \App\Domain\Shipping\Services\TrackingLink::for($this->resource),
            'weight_grams' => $this->weight_grams,
            'pieces' => $this->pieces,
            'cod_amount_minor' => $this->cod_amount_minor,
            'cod_status' => $this->cod_status,
            'cod_remitted_at' => $this->cod_remitted_at?->toIso8601String(),
            'cod_remittance_reference' => $this->cod_remittance_reference,
            'booking_status' => $this->booking_status,
            'booking_error' => $this->booking_error,
            'label_source' => $this->label_source,
            'last_polled_at' => $this->last_polled_at?->toIso8601String(),
            'restocked_at' => $this->restocked_at?->toIso8601String(),
            'actions' => [
                'book' => in_array($this->booking_status, ['failed', 'unknown'], true) && ! $this->status->isTerminal(),
                'cancel' => in_array($this->status->value, ['draft', 'ready', 'label_created', 'pickup_requested'], true) && $this->booking_status !== 'requesting',
                'refresh' => $this->booking_status === 'booked' && ! $this->status->isTerminal(),
                'restock' => ! $this->isReturn() && $this->status->value === 'returned' && $this->restocked_at === null,
                'set_tracking' => $this->carrier === \App\Domain\Shipping\Couriers\CourierRegistry::MANUAL && ! $this->status->isTerminal(),
            ],
            'items' => ShipmentItemResource::collection($this->whenLoaded('items')),
            'tracking_events' => ShipmentTrackingEventResource::collection($this->whenLoaded('trackingEvents')),
        ];
    }
}
