<?php

declare(strict_types=1);

namespace App\Domain\Returns\Http\Resources;

use App\Domain\Returns\Models\ReturnItem;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Returns\Models\ReturnStatus;
use App\Domain\Returns\Services\ReturnStateMachine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A return as staff (`forStaff`) or the customer who asked sees it.
 * The customer's view leaves out who of the staff did what, the
 * warehouse and the inspection notes; the decision note is written for
 * the customer and is shown to both.
 *
 * `can` tells the screen which steps the state allows next; whether the
 * person may take them is still decided by the policy on each call.
 *
 * @mixin ReturnRequest
 */
final class ReturnResource extends JsonResource
{
    private bool $staff = false;

    public function forStaff(): self
    {
        $this->staff = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $states = app(ReturnStateMachine::class);
        $status = $this->status;

        return [
            'id' => $this->public_id,
            'return_number' => $this->return_number,
            'status' => $status->value,
            'resolution' => $this->resolution->value,
            'reason' => $this->reason->value,
            'description' => $this->description,
            'requested_by' => $this->requested_by,
            'decision_note' => $this->decision_note,
            'return_method' => $this->return_method?->value,
            'return_shipping_paid_by' => $this->return_shipping_paid_by,
            'return_carrier' => $this->return_carrier,
            'return_tracking_number' => $this->return_tracking_number,
            'currency' => $this->currency,
            'items_refund_minor' => (int) $this->items_refund_minor,
            'shipping_refund_minor' => (int) $this->shipping_refund_minor,
            'restocking_fee_minor' => (int) $this->restocking_fee_minor,
            'refund_total_minor' => (int) $this->refund_total_minor,
            'refunded_minor' => (int) $this->refunded_minor,
            // Phase B34 (Module 09 §52): `payment` or `store_credit`, and what went to the customer's store credit.
            'refund_method' => $this->refund_method,
            'refunded_credit_minor' => (int) $this->refunded_credit_minor,
            ...($this->staff && $this->relationLoaded('order') ? ['store_credit_possible' => app(\App\Domain\Returns\Services\ReturnService::class)->storeCreditPossible($this->resource)] : []),
            'order' => $this->whenLoaded('order', fn () => [
                'id' => $this->order->public_id, 'order_number' => $this->order->order_number,
                ...($this->staff ? [
                    'customer_name' => $this->order->customer->name ?? $this->order->guest_name,
                    'payment_status' => $this->order->payment_status->value,
                    'shipping_total_minor' => (int) $this->order->shipping_total_minor,
                    'store_credit_minor' => (int) $this->order->store_credit_minor,
                ] : []),
            ]),
            'replacement_order' => $this->whenLoaded('replacementOrder', fn () => $this->replacementOrder === null ? null : [
                'id' => $this->replacementOrder->public_id, 'order_number' => $this->replacementOrder->order_number,
                'grand_total_minor' => (int) $this->replacementOrder->grand_total_minor,
            ]),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (ReturnItem $item) => [
                'order_item_id' => $item->order_item_id,
                'name' => $item->orderItem?->product_name_snapshot,
                'sku' => $item->orderItem?->sku_snapshot,
                'variant' => $item->orderItem?->variant_snapshot,
                'unit_price_minor' => (int) ($item->orderItem->unit_price_minor ?? 0),
                'quantity' => $item->quantity,
                'resalable_quantity' => $item->resalable_quantity,
                'damaged_quantity' => $item->damaged_quantity,
                'rejected_quantity' => $item->rejected_quantity,
                'refund_minor' => (int) $item->refund_minor,
                ...($this->staff ? ['inspection_note' => $item->inspection_note] : []),
            ])->values()),
            ...($this->staff ? ['warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse === null ? null : ['id' => $this->warehouse->id, 'name' => $this->warehouse->name])] : []),
            // Phase B34: the photos, by id; the image itself is read through the API (it has no public URL).
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(fn (\App\Domain\Returns\Models\ReturnPhoto $photo) => [
                'id' => $photo->public_id, 'width' => $photo->width, 'height' => $photo->height, 'uploaded_by' => $photo->uploaded_by,
            ])->values()),
            'can' => [
                'review' => $states->can($status, ReturnStatus::UnderReview),
                'approve' => $states->can($status, ReturnStatus::Approved),
                'reject' => in_array($status, [ReturnStatus::Requested, ReturnStatus::UnderReview], true),
                'cancel' => $states->can($status, ReturnStatus::Cancelled),
                'mark_in_transit' => $states->can($status, ReturnStatus::InTransit),
                'receive' => $states->can($status, ReturnStatus::Received),
                'inspect' => $states->can($status, ReturnStatus::Inspected),
                'approve_refund' => $status === ReturnStatus::Inspected && ($this->replacement_order_id === null || (int) $this->items_refund_minor > 0),
                'replace' => $status === ReturnStatus::Inspected && $this->replacement_order_id === null,
                'refund' => $status === ReturnStatus::ApprovedForRefund,
                // The person who asked adds photos until the store has answered; staff while the return is open.
                'add_photos' => $this->staff ? ! $status->isClosed() : in_array($status, [ReturnStatus::Requested, ReturnStatus::UnderReview], true),
            ],
            'created_at' => $this->created_at->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'shipped_back_at' => $this->shipped_back_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'inspected_at' => $this->inspected_at?->toIso8601String(),
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
