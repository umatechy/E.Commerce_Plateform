<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Http\Resources;

use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\Models\ShipmentTrackingEvent;
use App\Domain\Shipping\Services\ShippingLabelService;
use App\Domain\Shipping\Services\TrackingLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase B49 — Module 13 §74 "Customer Tracking": status, courier, tracking
 * number and link, dates and the tracking events. Never the internal
 * carrier data staff see (booking errors, courier references, cash on
 * delivery remittance, label), which the staff ShipmentResource now holds.
 *
 * @mixin Shipment
 */
final class CustomerShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'direction' => $this->direction,
            'carrier' => $this->carrier,
            'carrier_label' => app(ShippingLabelService::class)->carrierName($this->resource),
            'status' => $this->status->value,
            'tracking_number' => $this->tracking_number,
            'tracking_url' => TrackingLink::for($this->resource),
            'estimated_delivery_at' => $this->estimated_delivery_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'tracking_events' => $this->whenLoaded('trackingEvents', fn () => $this->trackingEvents->sortBy('occurred_at')->values()->map(fn (ShipmentTrackingEvent $event) => [
                'status' => $event->status->value,
                'description' => $event->description,
                'location' => $event->location,
                'occurred_at' => $event->occurred_at->toIso8601String(),
            ])->all()),
        ];
    }
}
