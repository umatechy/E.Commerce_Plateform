<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccount\Services;

use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Payments\Models\Payment;
use App\Domain\Shipping\Models\Shipment;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Phase B25 — a customer's own orders, in the shape a shopper sees them.
 * Every query is pinned to the signed-in customer (and, through the
 * tenant scope, to their store); no internal ids, no staff notes about
 * cancellation beyond the reason code.
 */
final class CustomerOrderHistory
{
    /** @return LengthAwarePaginator<int, Order> */
    public function paginate(Customer $customer, int $perPage): LengthAwarePaginator
    {
        return Order::query()
            ->where('customer_id', $customer->id)
            ->withCount('items')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage);
    }

    public function find(Customer $customer, string $publicId): ?Order
    {
        return Order::query()
            ->where('customer_id', $customer->id)
            ->where('public_id', $publicId)
            ->with(['items', 'shipments'])
            ->first();
    }

    /** @return array<string, mixed> */
    public function summary(Order $order): array
    {
        return [
            'id' => $order->public_id,
            'number' => $order->order_number,
            'status' => $order->status->value,
            'payment_status' => $order->payment_status->value,
            'fulfillment_status' => $order->fulfillment_status->value,
            'currency' => $order->currency,
            'grand_total_minor' => $order->grand_total_minor,
            'item_count' => (int) ($order->getAttribute('items_count') ?? $order->items->count()),
            'placed_at' => $order->created_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Order $order): array
    {
        return [
            ...$this->summary($order),
            'subtotal_minor' => $order->subtotal_minor,
            'discount_total_minor' => $order->discount_total_minor,
            'shipping_total_minor' => $order->shipping_total_minor,
            'tax_total_minor' => $order->tax_total_minor,
            'store_credit_minor' => (int) $order->store_credit_minor, // Phase B34
            'cancellation_reason' => $order->cancellation_reason?->value,
            'shipping_address' => $order->shipping_address_snapshot,
            'billing_address' => $order->billing_address_snapshot,
            'notes' => $order->notes,
            'items' => $order->items->map(fn (OrderItem $item) => [
                'name' => $item->product_name_snapshot,
                'sku' => $item->sku_snapshot,
                'variant' => $item->variant_snapshot,
                'quantity' => $item->quantity,
                'unit_price_minor' => $item->unit_price_minor,
                'line_total_minor' => $item->line_total_minor,
            ])->values()->all(),
            'payments' => Payment::query()->where('order_id', $order->id)->orderBy('id')->get()->map(fn (Payment $payment) => [
                'method' => $payment->method->value,
                'status' => $payment->status->value,
                'amount_minor' => $payment->amount_minor,
            ])->all(),
            'shipments' => $order->shipments->map(fn (Shipment $shipment) => [
                'id' => $shipment->public_id,
                'carrier' => $shipment->carrier,
                'status' => $shipment->status->value,
                'tracking_number' => $shipment->tracking_number,
                'shipped_at' => $shipment->shipped_at?->toIso8601String(),
                'delivered_at' => $shipment->delivered_at?->toIso8601String(),
                'estimated_delivery_at' => $shipment->estimated_delivery_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
