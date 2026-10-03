<?php

declare(strict_types=1);

namespace App\Domain\Returns\Services;

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Returns\Models\ReturnStatus;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Shipping\Models\ShipmentStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Module 09 §45, §47: what of an order can be returned.
 *
 * - Only what was delivered can come back: the quantities of the order's
 *   DELIVERED shipments (an order's own status does not follow shipping
 *   since B8, so the shipments are the truth).
 * - Minus what is already in a return that is still open or was
 *   accepted. Units a return rejected at inspection stay counted: they
 *   were judged once.
 * - A customer asks within the store's return window, counted from the
 *   delivery; staff may open a return later (their decision, recorded).
 * - Whether customers may ask at all is the store's setting (§45
 *   "where the store allows them"); staff can always record a return.
 */
final class ReturnEligibility
{
    public function __construct(private readonly ConfigService $config) {}

    public function customersMayRequest(): bool
    {
        return (bool) $this->config->get('returns.customer_requests_enabled');
    }

    public function windowDays(): int
    {
        return (int) $this->config->get('returns.window_days');
    }

    /**
     * Per order line: delivered, already in returns, and still returnable.
     *
     * @return array<int, array{order_item_id: int, name: string, sku: ?string, variant: ?array<string, mixed>, ordered: int, delivered: int, in_returns: int, returnable: int, delivered_at: ?string, window_open: bool, unit_price_minor: int}>
     */
    public function lines(Order $order): array
    {
        $delivered = DB::table('shipment_items')
            ->join('shipments', 'shipments.id', '=', 'shipment_items.shipment_id')
            ->where('shipments.store_id', $order->store_id)
            ->where('shipments.order_id', $order->id)
            ->where('shipments.status', ShipmentStatus::Delivered->value)
            ->groupBy('shipment_items.order_item_id')
            ->selectRaw('shipment_items.order_item_id as id, SUM(shipment_items.quantity) as quantity, MAX(shipments.delivered_at) as delivered_at')
            ->get()->keyBy('id');

        $held = DB::table('return_request_items')
            ->join('return_requests', 'return_requests.id', '=', 'return_request_items.return_request_id')
            ->where('return_requests.store_id', $order->store_id)
            ->where('return_requests.order_id', $order->id)
            ->whereNotIn('return_requests.status', [ReturnStatus::Rejected->value, ReturnStatus::Cancelled->value])
            ->groupBy('return_request_items.order_item_id')
            ->selectRaw('return_request_items.order_item_id as id, SUM(return_request_items.quantity) as quantity')
            ->pluck('quantity', 'id');

        $window = $this->windowDays();
        $lines = [];

        foreach ($order->items()->orderBy('id')->get() as $item) {
            /** @var OrderItem $item */
            $row = $delivered->get($item->id);
            $deliveredQuantity = (int) ($row->quantity ?? 0);
            $inReturns = (int) ($held[$item->id] ?? 0);
            $deliveredAt = $row?->delivered_at !== null ? Carbon::parse($row->delivered_at, 'UTC') : null;

            $lines[$item->id] = [
                'order_item_id' => $item->id,
                'name' => $item->product_name_snapshot,
                'sku' => $item->sku_snapshot,
                'variant' => $item->variant_snapshot,
                'ordered' => (int) $item->quantity,
                'delivered' => $deliveredQuantity,
                'in_returns' => $inReturns,
                'returnable' => max(0, $deliveredQuantity - $inReturns),
                'delivered_at' => $deliveredAt?->toIso8601String(),
                'window_open' => $deliveredAt !== null && $deliveredAt->copy()->addDays($window)->isFuture(),
                'unit_price_minor' => (int) $item->unit_price_minor,
            ];
        }

        return $lines;
    }

    /** Why this order cannot have a return at all, or null. */
    public function orderBlock(Order $order): ?string
    {
        if ($order->status === OrderStatus::Cancelled) {
            return 'This order was cancelled. A cancelled order has nothing to return.';
        }

        return null;
    }
}
