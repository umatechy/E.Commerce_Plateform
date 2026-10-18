<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Payments\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * UI-23 — what the dashboard suggests doing, worked out from the active
 * store's own figures (tenant scope); nothing is estimated or invented.
 * Each insight carries its numbers; the page words them. An insight is
 * left out when its figures are too few to mean anything.
 *
 * Sales count on orders that count (not draft, cancelled or failed) — the
 * same rule as best sellers and units sold.
 */
final class InsightsService
{
    private const NOT_COUNTED = ['draft', 'cancelled', 'failed'];

    /** @return list<array{key: string, tone: string, href: string, values: array<string, mixed>}> */
    public function all(): array
    {
        return array_values(array_filter([
            $this->sellingOut(),
            $this->codCancellations(),
            $this->waitingLong(),
            $this->topProduct(),
            $this->notSelling(),
        ]));
    }

    /** Units sold per product since a moment, on orders that count. */
    private function soldSince(\DateTimeInterface $since): \Illuminate\Database\Eloquent\Builder
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotIn('orders.status', self::NOT_COUNTED)
            ->where('orders.created_at', '>=', $since)
            ->whereNotNull('order_items.product_id')
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) AS units');
    }

    /** Best sellers of the last 7 days that are close to running out. */
    private function sellingOut(): ?array
    {
        $sold = $this->soldSince(now()->subDays(7))->having('units', '>=', 2)->orderByDesc('units')->limit(20)->pluck('units', 'order_items.product_id');
        if ($sold->isEmpty()) {
            return null;
        }
        $stock = Inventory::query()->whereIn('product_id', $sold->keys())->whereNull('product_variant_id')
            ->groupBy('product_id')->selectRaw('product_id, SUM(on_hand - reserved) AS available, MAX(reorder_point) AS reorder_point')->get()->keyBy('product_id');
        $products = Product::query()->whereIn('id', $sold->keys())->get(['id', 'public_id', 'name'])->keyBy('id');
        $items = [];
        foreach ($sold as $productId => $units) {
            $row = $stock->get($productId);
            if ($row === null || ! $products->has($productId)) {
                continue;
            }
            $available = (int) $row->getAttribute('available');
            if ($available <= max(5, (int) $row->reorder_point)) {
                $items[] = ['id' => $products[$productId]->public_id, 'name' => $products[$productId]->name, 'sold' => (int) $units, 'left' => max(0, $available)];
            }
            if (count($items) === 3) {
                break;
            }
        }

        return $items === [] ? null : ['key' => 'selling_out', 'tone' => 'amber', 'href' => '/inventory?stock=low', 'values' => ['products' => $items]];
    }

    /** The share of cash-on-delivery orders cancelled in the last 30 days, against the 30 days before. */
    private function codCancellations(): ?array
    {
        $rate = function (\DateTimeInterface $from, \DateTimeInterface $to): array {
            $orders = Order::query()->whereBetween('created_at', [$from, $to])
                ->whereIn('id', Payment::query()->where('method', 'cod')->select('order_id'));
            $total = (clone $orders)->count();
            $cancelled = (clone $orders)->where('status', OrderStatus::Cancelled->value)->count();

            return [$total, $total > 0 ? (int) round($cancelled * 100 / $total) : 0];
        };
        [$total, $percent] = $rate(now()->subDays(30), now());
        if ($total < 5 || $percent < 10) {
            return null;
        }
        [$before, $beforePercent] = $rate(now()->subDays(60), now()->subDays(30));

        return ['key' => 'cod_cancellations', 'tone' => $percent >= 25 ? 'red' : 'amber', 'href' => '/orders?status=cancelled', 'values' => [
            'percent' => $percent, 'orders' => $total, 'previous_percent' => $before >= 5 ? $beforePercent : null,
        ]];
    }

    /** Orders waiting for confirmation for more than a day. */
    private function waitingLong(): ?array
    {
        $count = Order::query()->where('status', OrderStatus::PendingConfirmation->value)->where('created_at', '<=', now()->subDay())->count();

        return $count === 0 ? null : ['key' => 'waiting_long', 'tone' => 'amber', 'href' => '/orders?status=pending_confirmation', 'values' => ['count' => $count]];
    }

    /** The best seller of the last 7 days. */
    private function topProduct(): ?array
    {
        $top = $this->soldSince(now()->subDays(7))->orderByDesc('units')->toBase()->first();
        if ($top === null || (int) $top->units < 3) {
            return null;
        }
        $product = Product::query()->find($top->product_id, ['id', 'public_id', 'name']);

        return $product === null ? null : ['key' => 'top_product', 'tone' => 'green', 'href' => "/products/{$product->public_id}", 'values' => ['name' => $product->name, 'sold' => (int) $top->units]];
    }

    /** Active products with stock that sold nothing in 30 days (and are older than that). */
    private function notSelling(): ?array
    {
        $since = now()->subDays(30);
        $count = Product::query()->where('status', 'active')->where('created_at', '<=', $since)
            ->whereIn('id', Inventory::query()->whereNull('product_variant_id')->whereRaw('(on_hand - reserved) > 0')->select('product_id'))
            ->whereNotIn('id', DB::query()->fromSub($this->soldSince($since)->toBase(), 'sold')->select('product_id'))
            ->count();

        return $count === 0 ? null : ['key' => 'not_selling', 'tone' => 'blue', 'href' => '/products?status=active', 'values' => ['count' => $count]];
    }
}
