<?php

declare(strict_types=1);

namespace App\Domain\Orders\Support;

use App\Domain\Orders\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * When an order was delivered, for everything that waits for delivery
 * (loyalty points, affiliate commissions).
 *
 * An order is delivered when all of it went out and every shipment arrived
 * (Module 13: shipments carry delivery; the order itself stays "confirmed"),
 * or when staff moved the order to delivered (Module 09 lifecycle). Before
 * this, affiliate commissions only knew the second way.
 */
final class OrderDelivery
{
    /** Order statuses from delivery on. */
    public const DELIVERED_STATUSES = ['delivered', 'completed', 'return_requested', 'partially_returned', 'partially_refunded', 'returned', 'refund_pending', 'refunded'];

    public static function deliveredAt(Order $order): ?Carbon
    {
        if ($order->fulfillment_status->value === 'fulfilled') {
            $shipments = DB::table('shipments')->where('order_id', $order->id)->where('status', '!=', 'cancelled');
            if (! (clone $shipments)->where('status', '!=', 'delivered')->exists() && ($last = (clone $shipments)->max('delivered_at')) !== null) {
                return Carbon::parse($last, 'UTC');
            }
        }
        if (! in_array($order->status->value, self::DELIVERED_STATUSES, true)) {
            return null;
        }
        $delivered = DB::table('order_timeline_events')->where('order_id', $order->id)->where('to_status', 'delivered')->min('created_at');

        return Carbon::parse($delivered ?? $order->completed_at ?? $order->updated_at);
    }
}
