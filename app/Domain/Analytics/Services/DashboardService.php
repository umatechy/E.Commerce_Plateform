<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Orders\Models\Customer;
use App\Domain\Payments\Models\PaymentTransaction;
use App\Domain\Payments\Models\TransactionStatus;
use App\Domain\Payments\Models\TransactionType;
use App\Domain\Inventory\Models\Inventory;
use Illuminate\Support\Carbon;

/**
 * Module 22 §10-11 "Store Dashboard / Dashboard Architecture". Every
 * KPI here maps to the exact, documented definition in
 * docs/development/b12-inspection-findings.md "Metric Dictionary" —
 * no metric is calculated ad hoc without a corresponding dictionary
 * entry. All aggregation is server-side SQL (COUNT/SUM), never PHP
 * iteration over hydrated models (Non-Negotiable Step 11).
 */
final class DashboardService
{
    public function __construct(private readonly DateRangeResolver $dateRanges) {}

    public function summary(Carbon $start, Carbon $end): array
    {
        $orders = Order::query()->whereBetween('created_at', [$start, $end]);
        $nonCancelledOrders = (clone $orders)->where('status', '!=', OrderStatus::Cancelled->value);

        $grossSales = (int) (clone $nonCancelledOrders)->sum('subtotal_minor');
        $discounts = (int) (clone $nonCancelledOrders)->sum('discount_total_minor');
        $shippingCharged = (int) (clone $nonCancelledOrders)->sum('shipping_total_minor');
        $revenue = (int) (clone $nonCancelledOrders)->sum('grand_total_minor');
        $orderCount = (clone $nonCancelledOrders)->count();

        $collected = (int) PaymentTransaction::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('type', [TransactionType::Sale, TransactionType::Capture])
            ->where('status', TransactionStatus::Succeeded)
            ->sum('amount_minor');

        $refunded = (int) PaymentTransaction::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('type', [TransactionType::Refund, TransactionType::PartialRefund])
            ->where('status', TransactionStatus::Succeeded)
            ->sum('amount_minor');

        $newCustomers = Customer::query()->whereBetween('created_at', [$start, $end])->count();

        // Module 08 §27 "Low Stock" — reuses B4's EXACT definition
        // (available = on_hand - reserved; low stock when available <=
        // reorder_point, only when a reorder_point is actually
        // configured) via raw SQL mirroring Inventory::isLowStock(),
        // rather than hydrating every Inventory row through PHP just to
        // call that method (Non-Negotiable Step 11: server-side
        // aggregation, no unnecessary hydration).
        $lowStockCount = Inventory::query()
            ->whereNotNull('reorder_point')
            ->whereRaw('(on_hand - reserved) <= reorder_point')
            ->count();

        return [
            'period' => ['start' => $start->toIso8601String(), 'end' => $end->toIso8601String()],
            'gross_sales_minor' => $grossSales,
            'discounts_minor' => $discounts,
            'net_sales_minor' => $grossSales - $discounts,
            'shipping_charged_minor' => $shippingCharged,
            'revenue_minor' => $revenue,
            'collected_amount_minor' => $collected,
            'refunded_amount_minor' => $refunded,
            'order_count' => $orderCount,
            'average_order_value_minor' => $orderCount > 0 ? intdiv($revenue, $orderCount) : null,
            'pending_orders' => (clone $orders)->where('status', OrderStatus::PendingConfirmation->value)->count(),
            'completed_orders' => (clone $orders)->where('status', OrderStatus::Completed->value)->count(),
            'new_customers' => $newCustomers,
            'low_stock_products' => $lowStockCount,
        ];
    }

    /**
     * Module 22 §13 "Comparative Periods" — Architectural Decision:
     * previous-period-of-equal-length only. Returns null percentage
     * change when the comparison base is zero (never a misleading
     * divide-by-zero result).
     */
    public function summaryWithComparison(Carbon $start, Carbon $end): array
    {
        $current = $this->summary($start, $end);
        [$previousStart, $previousEnd] = $this->dateRanges->previousPeriod($start, $end);
        $previous = $this->summary($previousStart, $previousEnd);

        return [
            'current' => $current,
            'previous' => $previous,
            'revenue_change_percent' => $this->percentChange($previous['revenue_minor'], $current['revenue_minor']),
            'order_count_change_percent' => $this->percentChange($previous['order_count'], $current['order_count']),
        ];
    }

    private function percentChange(int|float $previous, int|float $current): ?float
    {
        if ($previous == 0) {
            return null; // no meaningful percentage when there is nothing to compare against — never a fake 0%/divide-by-zero
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }
}
