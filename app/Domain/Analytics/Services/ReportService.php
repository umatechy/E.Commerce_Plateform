<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Promotions\Models\PromotionUsage;
use App\Domain\Marketing\Models\CampaignRecipient;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Inventory\Models\Inventory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Module 22 §16-33/§38-40 "Report Types / Report Definitions / Report
 * Filters". Every method here reads an authoritative source table
 * (never a second source of truth — this milestone's own Core
 * Principle), applies server-side aggregation, and returns a
 * paginated/grouped result. Sorting is ALWAYS resolved through a
 * fixed whitelist per report — never a client-supplied column name
 * passed directly into SQL (Non-Negotiable Step 13).
 */
final class ReportService
{
    /** Module 22 §16 "Order Analytics" — grouped by day (Order.created_at, per the documented per-report date-basis decision). */
    public function salesReport(Carbon $start, Carbon $end): array
    {
        $rows = Order::query()
            ->whereBetween('created_at', [$start, $end])
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as order_count, SUM(grand_total_minor) as revenue_minor, SUM(discount_total_minor) as discounts_minor')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return $rows->map(fn ($row) => [
            'date' => $row->date,
            'order_count' => (int) $row->order_count,
            'revenue_minor' => (int) $row->revenue_minor,
            'discounts_minor' => (int) $row->discounts_minor,
        ])->all();
    }

    /** Module 22 §17 "Product Analytics" — best-selling by revenue, whitelisted sort. */
    public function productsReport(Carbon $start, Carbon $end, string $sortBy = 'revenue', int $perPage = 25): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $sortColumn = match ($sortBy) {
            'quantity' => 'quantity_sold',
            'revenue' => 'revenue_minor',
            default => 'revenue_minor', // unrecognized sort silently falls back to the safe default — never passed through
        };

        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->selectRaw('order_items.product_id, order_items.product_name_snapshot, SUM(order_items.quantity) as quantity_sold, SUM(order_items.line_total_minor) as revenue_minor')
            ->groupBy('order_items.product_id', 'order_items.product_name_snapshot')
            ->orderByDesc($sortColumn)
            ->paginate($perPage);
    }

    /** Module 22 §19 "Customer Analytics". */
    public function customersReport(Carbon $start, Carbon $end): array
    {
        $newCustomers = \App\Domain\Orders\Models\Customer::query()->whereBetween('created_at', [$start, $end])->count();

        $orderCounts = Order::query()
            ->whereBetween('created_at', [$start, $end])
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, COUNT(*) as order_count')
            ->groupBy('customer_id')
            ->get();

        $customersWithOrders = $orderCounts->count();
        $repeatCustomers = $orderCounts->filter(fn ($row) => $row->order_count >= 2)->count();

        return [
            'new_customers' => $newCustomers,
            'customers_with_orders' => $customersWithOrders,
            'repeat_customers' => $repeatCustomers,
            // Module 22 Step 56: NULL, not 0, when there is no meaningful denominator.
            'repeat_customer_rate_percent' => $customersWithOrders > 0 ? round(($repeatCustomers / $customersWithOrders) * 100, 2) : null,
        ];
    }

    /** Module 22 §26 "Payment Analytics" — date basis is Payment.created_at (documented decision), status distribution from the authoritative Payment record. */
    public function paymentsReport(Carbon $start, Carbon $end): array
    {
        $rows = Payment::query()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('status, method, COUNT(*) as count, SUM(amount_minor) as amount_minor')
            ->groupBy('status', 'method')
            ->get();

        return $rows->map(fn ($row) => [
            'status' => $row->status instanceof \BackedEnum ? $row->status->value : $row->status,
            'method' => $row->method instanceof \BackedEnum ? $row->method->value : $row->method,
            'count' => (int) $row->count,
            'amount_minor' => (int) $row->amount_minor,
        ])->all();
    }

    /** Module 22 §27 "Shipping Analytics". */
    public function shippingReport(Carbon $start, Carbon $end): array
    {
        $rows = Shipment::query()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('status, carrier, COUNT(*) as count')
            ->groupBy('status', 'carrier')
            ->get();

        return $rows->map(fn ($row) => [
            'status' => $row->status instanceof \BackedEnum ? $row->status->value : $row->status,
            'carrier' => $row->carrier,
            'count' => (int) $row->count,
        ])->all();
    }

    /** Module 22 §25 "Promotion Analytics" — reuses B9's actual redemption ledger, never recalculates eligibility. */
    public function promotionsReport(Carbon $start, Carbon $end): array
    {
        $rows = PromotionUsage::query()
            ->join('promotions', 'promotions.id', '=', 'promotion_usages.promotion_id')
            ->whereBetween('promotion_usages.created_at', [$start, $end])
            ->selectRaw('promotions.id as promotion_id, promotions.name, COUNT(*) as usage_count, SUM(promotion_usages.discount_amount_minor) as total_discount_minor')
            ->groupBy('promotions.id', 'promotions.name')
            ->orderByDesc('usage_count')
            ->get();

        return $rows->map(fn ($row) => [
            'promotion_id' => $row->promotion_id, 'name' => $row->name,
            'usage_count' => (int) $row->usage_count, 'total_discount_minor' => (int) $row->total_discount_minor,
        ])->all();
    }

    /** Module 22 §23 "Marketing Analytics" — reuses B10's actual CampaignRecipient ledger, never rebuilds segmentation. */
    public function marketingReport(Carbon $start, Carbon $end): array
    {
        $rows = CampaignRecipient::query()
            ->join('campaigns', 'campaigns.id', '=', 'campaign_recipients.campaign_id')
            ->whereBetween('campaign_recipients.created_at', [$start, $end])
            ->selectRaw('campaigns.id as campaign_id, campaigns.name, campaign_recipients.status, COUNT(*) as count')
            ->groupBy('campaigns.id', 'campaigns.name', 'campaign_recipients.status')
            ->get();

        return $rows->map(fn ($row) => [
            'campaign_id' => $row->campaign_id, 'name' => $row->name,
            'status' => $row->status instanceof \BackedEnum ? $row->status->value : $row->status,
            'count' => (int) $row->count,
        ])->all();
    }

    /** Module 22 §24 "Communication Analytics" — "sent" is never conflated with "delivered" (this milestone's own explicit Step 33 rule). */
    public function notificationsReport(Carbon $start, Carbon $end): array
    {
        $rows = NotificationMessage::query()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('channel, status, COUNT(*) as count')
            ->groupBy('channel', 'status')
            ->get();

        return $rows->map(fn ($row) => [
            'channel' => $row->channel instanceof \BackedEnum ? $row->channel->value : $row->channel,
            'status' => $row->status instanceof \BackedEnum ? $row->status->value : $row->status,
            'count' => (int) $row->count,
        ])->all();
    }

    /** Module 22 §28 "Inventory Analytics" — read-only, reuses B4's exact isLowStock() formula (see DashboardService's identical fix). */
    public function inventoryReport(): array
    {
        $totals = Inventory::query()->selectRaw('SUM(on_hand) as total_on_hand, SUM(reserved) as total_reserved')->first();
        $lowStockCount = Inventory::query()->whereNotNull('reorder_point')->whereRaw('(on_hand - reserved) <= reorder_point')->count();
        $outOfStockCount = Inventory::query()->whereRaw('(on_hand - reserved) <= 0')->count();

        return [
            'total_on_hand' => (int) ($totals->total_on_hand ?? 0),
            'total_reserved' => (int) ($totals->total_reserved ?? 0),
            'low_stock_count' => $lowStockCount,
            'out_of_stock_count' => $outOfStockCount,
        ];
    }
}
