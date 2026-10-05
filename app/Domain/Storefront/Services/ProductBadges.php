<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Services;

use App\Domain\Catalog\Models\Badge;
use App\Domain\Catalog\Models\Product;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Settings\Services\TranslationService;
use Illuminate\Support\Facades\DB;

/**
 * Phase B43 — Module 06 §36 "Product badges", Module 17 §26 (card badges).
 *
 * The badges a product shows, worked out for a whole list at once (a fixed
 * number of queries, whatever the list's length):
 *
 *   automatic  out of stock · sale (with the % off when the price is one) ·
 *              low stock · new · bestseller · featured — each switched on
 *              or off and tuned in the store's settings (badges.*);
 *   the store's own badges put on the product (Badge), in their language.
 *
 * Badges are kept apart from the product's own data (§36): nothing is
 * stored on the product. They are ordered by priority (higher first; the
 * key breaks ties, so the order never changes between requests) and cut to
 * badges.max_per_product. An automatic badge has a fixed priority:
 * out of stock 100, sale 90, low stock 80, new 70, bestseller 60,
 * featured 40; the store's own badges carry their own (default 50).
 *
 * Automatic labels are not sent: the storefront shows its own words in the
 * visitor's language. Products must carry the catalog's pricing columns
 * (on_sale, in_stock, has_variants).
 */
final class ProductBadges
{
    public const AUTOMATIC = ['out_of_stock' => 100, 'sale' => 90, 'low_stock' => 80, 'new' => 70, 'bestseller' => 60, 'featured' => 40];

    private const TONES = ['out_of_stock' => 'neutral', 'sale' => 'danger', 'low_stock' => 'warning', 'new' => 'accent', 'bestseller' => 'success', 'featured' => 'accent'];

    /**
     * @param iterable<Product> $products
     * @return array<int, list<array{type: string, label: ?string, tone: string, percent?: int}>> product id => badges
     */
    public function forProducts(iterable $products): array
    {
        $list = collect($products)->values();
        if ($list->isEmpty()) {
            return [];
        }
        $config = app(ConfigService::class);
        $on = fn (string $badge) => (bool) $config->get("badges.{$badge}");
        $ids = $list->pluck('id')->map(fn ($id) => (int) $id)->all();
        $catalog = app(StorefrontCatalog::class);

        $bestsellers = $on('bestseller') ? array_flip($catalog->bestsellerIds((int) $config->get('badges.bestseller_count'), (int) $config->get('badges.bestseller_days'))) : [];
        $units = $on('low_stock') ? $catalog->stockUnits($ids) : [];
        $lowStock = (int) $config->get('badges.low_stock_threshold');
        $newSince = now()->subDays((int) $config->get('badges.new_days'));
        $custom = $this->customBadges($ids);
        $max = max(1, (int) $config->get('badges.max_per_product'));

        $out = [];
        foreach ($list as $product) {
            /** @var Product $product */
            $inStock = (bool) $product->getAttribute('in_stock');
            $badges = [];
            if ($on('out_of_stock') && ! $inStock) {
                $badges[] = $this->auto('out_of_stock');
            }
            if ($on('sale') && (bool) $product->getAttribute('on_sale')) {
                $badge = $this->auto('sale');
                $percent = $on('sale_percent') ? $this->percentOff($product) : null;
                if ($percent !== null) {
                    $badge['percent'] = $percent;
                }
                $badges[] = $badge;
            }
            if ($on('low_stock') && $inStock && isset($units[$product->id]) && $units[$product->id] > 0 && $units[$product->id] <= $lowStock) {
                $badges[] = $this->auto('low_stock');
            }
            if ($on('new') && ($product->published_at ?? $product->created_at)?->greaterThanOrEqualTo($newSince)) {
                $badges[] = $this->auto('new');
            }
            if ($on('bestseller') && isset($bestsellers[$product->id])) {
                $badges[] = $this->auto('bestseller');
            }
            if ($on('featured') && $product->is_featured) {
                $badges[] = $this->auto('featured');
            }
            foreach ($custom[$product->id] ?? [] as $badge) {
                $badges[] = $badge;
            }

            usort($badges, fn (array $a, array $b) => [$b['priority'], $a['type'].($a['label'] ?? '')] <=> [$a['priority'], $b['type'].($b['label'] ?? '')]);
            $out[$product->id] = array_map(function (array $badge) {
                unset($badge['priority']);

                return $badge;
            }, array_slice($badges, 0, $max));
        }

        return $out;
    }

    /** @return array{type: string, label: null, tone: string, priority: int} */
    private function auto(string $type): array
    {
        return ['type' => $type, 'label' => null, 'tone' => self::TONES[$type], 'priority' => self::AUTOMATIC[$type]];
    }

    /** The whole-percent saving of a product with one price, or null (variants may differ). */
    private function percentOff(Product $product): ?int
    {
        if ((bool) $product->getAttribute('has_variants') || $product->price_minor === null || $product->sale_price_minor === null || $product->price_minor <= 0) {
            return null;
        }
        $percent = (int) floor(($product->price_minor - $product->sale_price_minor) * 100 / $product->price_minor);

        return $percent >= 1 ? $percent : null;
    }

    /**
     * The active store badges of these products, labels in the visitor's language.
     *
     * @param list<int> $productIds
     * @return array<int, list<array{type: string, label: string, tone: string, priority: int}>>
     */
    private function customBadges(array $productIds): array
    {
        $rows = DB::table('product_badge')->whereIn('product_id', $productIds)->get(['product_id', 'badge_id']);
        if ($rows->isEmpty()) {
            return [];
        }
        $badges = Badge::query()->whereIn('id', $rows->pluck('badge_id')->unique())->where('is_active', true)->get()->keyBy('id'); // tenant-scoped
        $locale = app(StorefrontLocale::class);
        $translations = app(TranslationService::class);
        if (! $locale->isDefault()) {
            $translations->prime('badge', $badges->keys()->all(), $locale->current());
        }
        $out = [];
        foreach ($rows as $row) {
            $badge = $badges->get($row->badge_id);
            if ($badge !== null) {
                $out[(int) $row->product_id][] = [
                    'type' => 'custom',
                    'label' => (string) $translations->value('badge', $badge->id, 'label', $badge->label, $locale->current(), $locale->default()),
                    'tone' => $badge->tone,
                    'priority' => $badge->priority,
                ];
            }
        }

        return $out;
    }
}
