<?php

declare(strict_types=1);

namespace App\Domain\Returns\Services;

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;

/**
 * Module 09 §50 "Refund Calculation": what the returned units are worth,
 * from what the customer actually paid for them.
 *
 *   line paid   = line total (unit price × quantity − line discount)
 *                 − this line's share of the order-level discount
 *                 + this line's tax
 *   unit value  = line paid ÷ ordered quantity
 *   line refund = unit value × accepted quantity, rounded DOWN to the
 *                 minor unit — except that returning the whole line
 *                 returns the whole line paid (no rounding loss).
 *
 * Shipping is not part of it (§50 "shipping policy": staff add a
 * shipping refund explicitly, never more than the order's shipping), and
 * a restocking fee is a deduction staff enter. Previous refunds are
 * respected by the payment's refundable balance (Module 12 §48) and by
 * a unit being returnable only once (ReturnEligibility).
 *
 * Integers only (ADR-003): no floating point, no assumed 2 decimals.
 */
final class RefundCalculator
{
    /**
     * @param array<int, int> $acceptedByOrderItem order_item_id => accepted quantity
     * @return array<int, int> order_item_id => refund in minor units
     */
    public function forItems(Order $order, array $acceptedByOrderItem): array
    {
        $items = $order->items()->orderBy('id')->get()->keyBy('id');
        $lineTotals = (int) $items->sum('line_total_minor');
        $lineDiscounts = (int) $items->sum('discount_minor');
        // `discount_total_minor` is every discount of the order; what is not on a line is order-level.
        $orderLevelDiscount = max(0, (int) $order->discount_total_minor - $lineDiscounts);

        // The order-level discount is spread over the lines by their totals;
        // the last line takes the remainder, so the shares add up exactly.
        $shares = [];
        $spread = 0;
        $lastId = $items->keys()->last();
        foreach ($items as $id => $item) {
            /** @var OrderItem $item */
            $share = $id === $lastId
                ? $orderLevelDiscount - $spread
                : ($lineTotals > 0 ? intdiv($orderLevelDiscount * (int) $item->line_total_minor, $lineTotals) : 0);
            $shares[$id] = $share;
            $spread += $share;
        }

        $refunds = [];
        foreach ($acceptedByOrderItem as $id => $quantity) {
            $item = $items->get($id);
            if ($item === null || $quantity <= 0) {
                continue;
            }
            $quantity = min($quantity, (int) $item->quantity);
            $paid = max(0, (int) $item->line_total_minor - $shares[$id] + (int) $item->tax_minor);
            $refunds[$id] = $quantity === (int) $item->quantity ? $paid : intdiv($paid * $quantity, (int) $item->quantity);
        }

        return $refunds;
    }
}
