<?php

declare(strict_types=1);

namespace App\Domain\Promotions\Services;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Promotions\Models\Promotion;
use Illuminate\Support\Collection;

/**
 * Phase B32: names for a promotion's targets, so the admin shows
 * "Rose Attar" instead of "Product #12". One query per target kind for a
 * whole page of promotions; tenant-scoped like every catalog query. A
 * target deleted since keeps its id and gets no name.
 */
final class PromotionTargetNames
{
    /**
     * Attaches the names as the `targetNames` relation (id => name).
     *
     * @param iterable<Promotion> $promotions with `targets` loaded
     */
    public function attach(iterable $promotions): void
    {
        $promotions = collect($promotions);
        $ids = ['product' => [], 'category' => [], 'brand' => [], 'collection' => []];
        foreach ($promotions as $promotion) {
            foreach ($promotion->targets as $target) {
                $type = (string) $target->target_type;
                if (isset($ids[$type])) {
                    $ids[$type][] = (int) $target->target_id;
                }
            }
        }

        $names = [
            'product' => $this->names(Product::class, $ids['product']),
            'category' => $this->names(Category::class, $ids['category']),
            'brand' => $this->names(Brand::class, $ids['brand']),
            'collection' => $this->names(\App\Domain\Catalog\Models\Collection::class, $ids['collection']),
        ];

        foreach ($promotions as $promotion) {
            $scope = $promotion->target_scope->value;
            $promotion->setRelation('targetNames', collect($names[$scope] ?? []));
        }
    }

    /**
     * @param class-string<Product|Category|Brand|\App\Domain\Catalog\Models\Collection> $model
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function names(string $model, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var Collection<int, string> $found */
        $found = $model::query()->whereIn('id', array_unique($ids))->pluck('name', 'id');

        return $found->all();
    }
}
