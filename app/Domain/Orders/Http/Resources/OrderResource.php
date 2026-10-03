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
            // Phase B33 (Module 09 §47): the order's summary of its returns, and what it replaces (§54).
            'return_status' => $this->return_status->value,
            'replacement_for' => $this->replacement_for_order_id === null ? null : (fn ($original) => $original === null ? null : ['id' => $original->public_id, 'order_number' => $original->order_number])($this->replacementFor),
            'source' => $this->source->value,
            'currency' => $this->currency,
            'subtotal_minor' => $this->subtotal_minor,
            'discount_total_minor' => $this->discount_total_minor,
            'tax_total_minor' => $this->tax_total_minor,
            'shipping_total_minor' => $this->shipping_total_minor,
            'grand_total_minor' => $this->grand_total_minor,
            'is_guest_order' => $this->isGuestOrder(),
            'guest_name' => $this->when($this->isGuestOrder(), $this->guest_name),
            'guest_email' => $this->when($this->isGuestOrder(), $this->guest_email),
            'guest_phone' => $this->when($this->isGuestOrder(), $this->guest_phone),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer === null ? null : ['id' => $this->customer->public_id, 'name' => $this->customer->name, 'email' => $this->customer->email]),
            'shipping_address' => $this->shipping_address_snapshot,
            'billing_address' => $this->billing_address_snapshot,
            'notes' => $this->notes,
            'cancellation_reason' => $this->cancellation_reason?->value,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
