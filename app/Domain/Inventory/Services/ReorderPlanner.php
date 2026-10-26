<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase P4a (Module 08 §71 "advanced stock reports"): what to order again.
 *
 * Per product (or variant), over all locations:
 * - sold per day = units sold in the last $salesDays days (orders not
 *   cancelled) ÷ $salesDays;
 * - days left = available (on hand less reserved) ÷ sold per day — null when
 *   nothing sold, as there is no rate to divide by;
 * - suggested = enough for $coverDays days at that rate, less what is
 *   available and already on its way (incoming), never below zero; at least
 *   the record's own reorder quantity when the stock is at its reorder point.
 *
 * Both periods are the store's choice on the page; nothing here is a
 * forecast model — it is the plain arithmetic, shown with it.
 */
final class ReorderPlanner
{
    /**
     * @return list<array{product_id: int, product_variant_id: ?int, product: string, variant: ?string, sku: ?string, available: int, incoming: int, sold: int, per_day: float, days_left: ?float, reorder_point: ?int, suggested: int, state: string}>
     */
    public function plan(int $salesDays, int $coverDays): array
    {
        $storeId = app(TenantContext::class)->storeId();
        $since = now()->subDays($salesDays);
        $sold = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $storeId)->where('orders.created_at', '>=', $since)->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereNotNull('order_items.product_id')
            ->groupBy('order_items.product_id', 'order_items.product_variant_id')
            ->selectRaw('order_items.product_id, order_items.product_variant_id, SUM(order_items.quantity) as units')->get()
            ->keyBy(fn ($row) => $row->product_id.':'.($row->product_variant_id ?? ''));

        $rows = DB::table('inventories')->join('products', 'products.id', '=', 'inventories.product_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'inventories.product_variant_id')
            ->where('inventories.store_id', $storeId)->whereNull('products.deleted_at')->where('products.status', '!=', 'archived')
            ->groupBy('inventories.product_id', 'inventories.product_variant_id')
            ->selectRaw('inventories.product_id, inventories.product_variant_id, MAX(products.name) as product, MAX(COALESCE(product_variants.sku, products.sku)) as sku,
                SUM(inventories.on_hand - inventories.reserved) as available, SUM(inventories.incoming) as incoming,
                MAX(inventories.reorder_point) as reorder_point, MAX(inventories.reorder_quantity) as reorder_quantity')
            ->get();
        // A variant is named by its options ("Black / M").
        $variants = DB::table('product_variants')->whereIn('id', $rows->pluck('product_variant_id')->filter()->all() ?: [0])->pluck('option_values', 'id')
            ->map(fn ($json) => $json === null ? null : implode(' / ', array_map('strval', (array) json_decode((string) $json, true))));

        $plan = [];
        foreach ($rows as $row) {
            $units = (int) ($sold[$row->product_id.':'.($row->product_variant_id ?? '')]->units ?? 0);
            $available = max(0, (int) $row->available);
            $incoming = (int) $row->incoming;
            $perDay = $units / $salesDays;
            $daysLeft = $perDay > 0 ? round($available / $perDay, 1) : null;
            $point = $row->reorder_point === null ? null : (int) $row->reorder_point;
            $suggested = max(0, (int) ceil($perDay * $coverDays) - $available - $incoming);
            if ($point !== null && $available <= $point) {
                $suggested = max($suggested, (int) ($row->reorder_quantity ?? 0) - $incoming);
            }
            $state = match (true) {
                $available === 0 && ($units > 0 || $point !== null) => 'out',
                $point !== null && $available <= $point => 'below_point',
                $daysLeft !== null && $daysLeft < $coverDays => 'running_low',
                default => 'ok',
            };
            if ($state === 'ok' && $suggested === 0) {
                continue;
            }
            $plan[] = [
                'product_id' => (int) $row->product_id, 'product_variant_id' => $row->product_variant_id === null ? null : (int) $row->product_variant_id,
                'product' => (string) $row->product, 'variant' => $row->product_variant_id === null ? null : ($variants[$row->product_variant_id] ?? null), 'sku' => $row->sku,
                'available' => $available, 'incoming' => $incoming, 'sold' => $units, 'per_day' => round($perDay, 2),
                'days_left' => $daysLeft, 'reorder_point' => $point, 'suggested' => max(0, $suggested), 'state' => $state,
            ];
        }
        $order = ['out' => 0, 'below_point' => 1, 'running_low' => 2, 'ok' => 3];
        usort($plan, fn (array $a, array $b) => [$order[$a['state']], $a['days_left'] ?? INF] <=> [$order[$b['state']], $b['days_left'] ?? INF]);

        return $plan;
    }
}
