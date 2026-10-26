<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Settings\Services\StoreClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase P3 — advanced analytics (Premium, `advanced_analytics.enabled`):
 * Module 22 §13 comparative periods, §15 revenue metrics with their
 * definitions, §17 product performance, §19 customer analytics, §21–22 cart
 * conversion with its denominator.
 *
 * Every figure is aggregated from the authoritative tables of the active
 * store (tenant-scoped models, or `store_id` on raw queries); nothing is
 * stored twice. A rate whose denominator is zero is null, never 0%.
 *
 * What is NOT measured, because the platform does not record it: product
 * views and checkout sessions (no such events yet). No figure here pretends
 * otherwise — cart conversion is carts that became orders, per cart.
 *
 * Money is in the store currency; orders in another currency (from before a
 * currency change) are left out of money figures, never mixed (§14).
 */
final class AdvancedAnalyticsService
{
    /** A cart untouched this long without becoming an order counts as abandoned. */
    public const ABANDONED_AFTER_HOURS = 24;

    public const COHORT_MONTHS = 6;

    public function __construct(private readonly StoreClock $clock) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(int $storeId, string $currency, Carbon $start, Carbon $end, Carbon $compareStart, Carbon $compareEnd, bool $financial): array
    {
        $current = $this->period($storeId, $currency, $start, $end);
        $previous = $this->period($storeId, $currency, $compareStart, $compareEnd);
        $kpis = [];
        foreach (['orders', 'ordering_customers', 'new_customers', 'returning_customers', ...($financial ? ['net_sales_minor', 'average_order_value_minor'] : [])] as $key) {
            $kpis[$key] = ['current' => $current[$key], 'previous' => $previous[$key], 'change_percent' => self::change($previous[$key], $current[$key])];
        }

        return [
            'currency' => $currency,
            'kpis' => $kpis,
            'sales' => $financial ? $current['sales'] : null,
            'customers' => [
                'guest_orders' => $current['guest_orders'],
                'registered_orders' => $current['registered_orders'],
                'repeat_rate_percent' => $current['repeat_rate_percent'],
                'orders_per_customer' => $current['orders_per_customer'],
                'average_lifetime_value_minor' => $financial ? $current['average_lifetime_value_minor'] : null,
            ],
            'cohorts' => $this->cohorts($storeId),
            'carts' => $this->carts($storeId, $currency, $start, $end, $financial),
            'products' => [
                'slow_movers' => $this->slowMovers($storeId, $start, $end),
                'returns' => $this->productReturns($storeId, $start, $end),
            ],
        ];
    }

    /** Percentage change, or null when there is nothing to compare with. */
    public static function change(int|float|null $previous, int|float|null $current): ?float
    {
        if ($previous === null || $current === null || $previous == 0) {
            return null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }

    /** @return array<string, mixed> */
    private function period(int $storeId, string $currency, Carbon $start, Carbon $end): array
    {
        $orders = fn () => Order::query()->whereBetween('created_at', [$start, $end])->where('status', '!=', OrderStatus::Cancelled->value);
        $money = fn () => $orders()->where('currency', $currency);

        $count = $orders()->count();
        $registered = $orders()->whereNotNull('customer_id')->count();
        $customerIds = $orders()->whereNotNull('customer_id')->distinct()->pluck('customer_id');
        $guestEmails = $orders()->whereNull('customer_id')->whereNotNull('guest_email')->distinct()->count('guest_email');

        // New: the customer's first order (ever, not cancelled) is in this period.
        $firstOrders = DB::table('orders')->where('store_id', $storeId)->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereIn('customer_id', $customerIds->all() ?: [0])->groupBy('customer_id')->selectRaw('customer_id, MIN(created_at) as first_at, COUNT(*) as n')->get();
        $new = $firstOrders->filter(fn ($row) => Carbon::parse($row->first_at, 'UTC')->greaterThanOrEqualTo($start))->count();
        $repeat = $firstOrders->filter(fn ($row) => (int) $row->n >= 2)->count();

        $gross = (int) $money()->sum('subtotal_minor');
        $discounts = (int) $money()->sum('discount_total_minor');
        $refunds = $this->refunds($storeId, $currency, $start, $end);
        $net = $gross - $discounts - $refunds;
        $moneyOrders = $money()->count();
        $lifetime = $customerIds->isEmpty() ? null : (int) round((float) DB::table('orders')->where('store_id', $storeId)->where('currency', $currency)
            ->where('status', '!=', OrderStatus::Cancelled->value)->whereIn('customer_id', $customerIds->all())
            ->selectRaw('SUM(grand_total_minor) / COUNT(DISTINCT customer_id) as v')->value('v'));

        return [
            'orders' => $count,
            'ordering_customers' => $customerIds->count() + $guestEmails,
            'new_customers' => $new,
            'returning_customers' => $customerIds->count() - $new,
            'net_sales_minor' => $net,
            'average_order_value_minor' => $moneyOrders > 0 ? (int) round((int) $money()->sum('grand_total_minor') / $moneyOrders) : null,
            'sales' => [
                'gross_minor' => $gross,
                'discounts_minor' => $discounts,
                'refunds_minor' => $refunds,
                'net_minor' => $net,
                'shipping_minor' => (int) $money()->sum('shipping_total_minor'),
                'tax_minor' => (int) $money()->sum('tax_total_minor'),
                'total_minor' => (int) $money()->sum('grand_total_minor'),
            ],
            'guest_orders' => $count - $registered,
            'registered_orders' => $registered,
            'repeat_rate_percent' => $customerIds->isEmpty() ? null : round($repeat / $customerIds->count() * 100, 1),
            'orders_per_customer' => $customerIds->isEmpty() ? null : round($registered / $customerIds->count(), 2),
            'average_lifetime_value_minor' => $lifetime,
        ];
    }

    /** Money given back in the period: payment refunds and refunds of returns as store credit. */
    private function refunds(int $storeId, string $currency, Carbon $start, Carbon $end): int
    {
        $payments = (int) DB::table('payment_transactions')
            ->join('payments', 'payments.id', '=', 'payment_transactions.payment_id')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.store_id', $storeId)->where('orders.currency', $currency)
            ->whereIn('payment_transactions.type', ['refund', 'partial_refund'])->where('payment_transactions.status', 'succeeded')
            ->whereBetween('payment_transactions.created_at', [$start, $end])->sum('payment_transactions.amount_minor');
        $credit = (int) DB::table('store_credit_entries')->where('store_id', $storeId)->where('currency', $currency)
            ->where('type', 'return_refund')->whereBetween('created_at', [$start, $end])->sum('amount_minor');

        return $payments + $credit;
    }

    /**
     * Retention by the month of the first order (store time): of the
     * customers who first ordered in a month, the share who ordered again in
     * each following month. Null for months that have not happened yet.
     *
     * @return list<array{month: string, customers: int, retention: list<?float>}>
     */
    private function cohorts(int $storeId): array
    {
        $zone = $this->clock->timezone();
        $first = $this->clock->now()->startOfMonth()->subMonths(self::COHORT_MONTHS - 1);
        $firstUtc = $first->copy()->utc();
        $starts = DB::table('orders')->where('store_id', $storeId)->whereNotNull('customer_id')->where('status', '!=', OrderStatus::Cancelled->value)
            ->groupBy('customer_id')->havingRaw('MIN(created_at) >= ?', [$firstUtc])->selectRaw('customer_id, MIN(created_at) as first_at')->pluck('first_at', 'customer_id');
        $month = fn (string $utc) => Carbon::parse($utc, 'UTC')->setTimezone($zone)->format('Y-m');
        $index = fn (string $ym) => ((int) substr($ym, 0, 4)) * 12 + (int) substr($ym, 5, 2);

        $later = [];
        DB::table('orders')->where('store_id', $storeId)->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereIn('customer_id', $starts->keys()->all() ?: [0])->orderBy('id')->select(['id', 'customer_id', 'created_at'])
            ->chunkById(1000, function ($rows) use (&$later, $starts, $month, $index) {
                foreach ($rows as $row) {
                    $offset = $index($month((string) $row->created_at)) - $index($month((string) $starts[$row->customer_id]));
                    if ($offset > 0) {
                        $later[$row->customer_id][$offset] = true;
                    }
                }
            });

        $nowIndex = $index($this->clock->now()->format('Y-m'));
        $cohorts = [];
        for ($i = 0; $i < self::COHORT_MONTHS; $i++) {
            $ym = $first->copy()->addMonthsNoOverflow($i)->format('Y-m');
            $members = $starts->filter(fn ($at) => $month((string) $at) === $ym)->keys();
            $retention = [];
            for ($m = 1; $m < self::COHORT_MONTHS; $m++) {
                $retention[] = $index($ym) + $m > $nowIndex || $members->isEmpty()
                    ? null
                    : round($members->filter(fn ($id) => isset($later[$id][$m]))->count() / $members->count() * 100, 1);
            }
            $cohorts[] = ['month' => $ym, 'customers' => $members->count(), 'retention' => $retention];
        }

        return $cohorts;
    }

    /** @return array<string, mixed> */
    private function carts(int $storeId, string $currency, Carbon $start, Carbon $end, bool $financial): array
    {
        // Carts with at least one item, created in the period; merged carts live on in the cart they joined.
        $carts = fn () => DB::table('carts')->where('store_id', $storeId)->whereBetween('created_at', [$start, $end])->where('status', '!=', 'merged')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('cart_items')->whereColumn('cart_items.cart_id', 'carts.id'));
        $created = $carts()->count();
        $converted = $carts()->whereNotNull('converted_order_id')->count();
        $abandoned = $carts()->whereNull('converted_order_id')
            ->where(fn ($q) => $q->where('status', 'abandoned')->orWhere(fn ($a) => $a->whereIn('status', ['active', 'checkout_started'])->where('updated_at', '<', now()->subHours(self::ABANDONED_AFTER_HOURS))))->count();
        $value = $financial ? DB::table('orders')->whereIn('id', $carts()->whereNotNull('converted_order_id')->select('converted_order_id'))->where('currency', $currency)->avg('grand_total_minor') : null;
        $attempts = DB::table('payment_attempts')->where('store_id', $storeId)->where('purpose', 'order')->whereBetween('created_at', [$start, $end]);
        $attemptCount = (clone $attempts)->whereIn('status', ['succeeded', 'failed', 'expired'])->count();
        $failed = (clone $attempts)->whereIn('status', ['failed', 'expired'])->count();

        return [
            'created' => $created,
            'converted' => $converted,
            'abandoned' => $abandoned,
            'conversion_percent' => $created > 0 ? round($converted / $created * 100, 1) : null,
            'average_order_value_minor' => $value === null ? null : (int) round((float) $value),
            'online_payments' => $attemptCount,
            'online_payment_failure_percent' => $attemptCount > 0 ? round($failed / $attemptCount * 100, 1) : null,
        ];
    }

    /**
     * Active products with stock that sold nothing in the period — the ten
     * holding the most units.
     *
     * @return list<array{id: string, name: string, available: int, last_sold_at: ?string}>
     */
    private function slowMovers(int $storeId, Carbon $start, Carbon $end): array
    {
        $sold = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->where('orders.store_id', $storeId)
            ->whereBetween('orders.created_at', [$start, $end])->where('orders.status', '!=', OrderStatus::Cancelled->value)->whereNotNull('order_items.product_id')->select('order_items.product_id');

        return DB::table('products')->join('inventories', 'inventories.product_id', '=', 'products.id')
            ->where('products.store_id', $storeId)->where('products.status', 'active')->whereNull('products.deleted_at')
            ->whereNotIn('products.id', $sold)
            ->groupBy('products.id', 'products.public_id', 'products.name')
            ->havingRaw('SUM(inventories.on_hand - inventories.reserved) > 0')
            ->selectRaw('products.id, products.public_id, products.name, SUM(inventories.on_hand - inventories.reserved) as available')
            ->orderByDesc('available')->limit(10)->get()
            ->map(fn ($row) => [
                'id' => (string) $row->public_id,
                'name' => (string) $row->name,
                'available' => (int) $row->available,
                'last_sold_at' => ($at = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->where('order_items.product_id', $row->id)
                    ->where('orders.status', '!=', OrderStatus::Cancelled->value)->max('orders.created_at')) === null ? null : Carbon::parse($at, 'UTC')->toIso8601String(),
            ])->all();
    }

    /**
     * Products sold in the period and how many of those units came back
     * (accepted in a return: resalable or damaged) — the ten returned most.
     *
     * @return list<array{name: string, sold: int, returned: int, return_percent: ?float}>
     */
    private function productReturns(int $storeId, Carbon $start, Carbon $end): array
    {
        $rows = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('return_request_items', 'return_request_items.order_item_id', '=', 'order_items.id')
            ->leftJoin('return_requests', fn ($j) => $j->on('return_requests.id', '=', 'return_request_items.return_request_id')->whereNotIn('return_requests.status', ['rejected', 'cancelled']))
            ->where('orders.store_id', $storeId)->whereBetween('orders.created_at', [$start, $end])->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->groupBy('order_items.product_name_snapshot')
            ->selectRaw('order_items.product_name_snapshot as name, SUM(CASE WHEN return_requests.id IS NULL THEN 0 ELSE return_request_items.resalable_quantity + return_request_items.damaged_quantity END) as returned')
            ->havingRaw('returned > 0')->orderByDesc('returned')->limit(10)->get();
        $soldBy = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $storeId)->whereBetween('orders.created_at', [$start, $end])->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereIn('order_items.product_name_snapshot', $rows->pluck('name')->all() ?: [''])
            ->groupBy('order_items.product_name_snapshot')->selectRaw('order_items.product_name_snapshot as name, SUM(order_items.quantity) as sold')->pluck('sold', 'name');

        return $rows->map(function ($row) use ($soldBy) {
            $sold = (int) ($soldBy[$row->name] ?? 0);

            return ['name' => (string) $row->name, 'sold' => $sold, 'returned' => (int) $row->returned, 'return_percent' => $sold > 0 ? round((int) $row->returned / $sold * 100, 1) : null];
        })->all();
    }
}
