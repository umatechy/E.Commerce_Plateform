<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Attribute;
use App\Domain\Catalog\Models\AttributeSet;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\CategoryAttribute;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase B41 — Module 07 §18–19, §44–45: which attributes apply to a
 * category, which are required for its products and which customers can
 * filter by.
 *
 * A category without its own list uses its nearest parent's ("Shoes >
 * Sneakers" follows "Shoes" until Sneakers gets its own).
 */
final class CategoryAttributes
{
    public const MAX_PER_CATEGORY = 40;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param array<int, array<string, mixed>> $items {attribute_id, is_required?, is_filter?} in order
     */
    public function set(Category $category, array $items, User $actor): void
    {
        if (count($items) > self::MAX_PER_CATEGORY) {
            throw ValidationException::withMessages(['attributes' => 'At most '.self::MAX_PER_CATEGORY.' attributes per category.']);
        }
        $ids = array_map(fn (array $item) => (int) $item['attribute_id'], $items);
        if (count(array_unique($ids)) !== count($ids)) {
            throw ValidationException::withMessages(['attributes' => 'An attribute is listed twice.']);
        }
        $attributes = Attribute::query()->whereIn('id', $ids)->get()->keyBy('id'); // tenant-scoped
        if ($attributes->count() !== count($ids)) {
            throw ValidationException::withMessages(['attributes' => 'One of the attributes is not in this store.']);
        }
        foreach ($items as $i => $item) {
            if (($item['is_filter'] ?? false) && ! $attributes[(int) $item['attribute_id']]->type->isFilterable()) {
                throw ValidationException::withMessages(["attributes.{$i}.is_filter" => 'A text attribute cannot be a filter.']);
            }
        }

        DB::transaction(function () use ($category, $items) {
            // Row by row, so the storefront cache hears of each change.
            CategoryAttribute::query()->where('category_id', $category->id)->get()->each->delete();
            foreach (array_values($items) as $position => $item) {
                CategoryAttribute::query()->create([
                    'category_id' => $category->id, 'attribute_id' => (int) $item['attribute_id'],
                    'is_required' => (bool) ($item['is_required'] ?? false), 'is_filter' => (bool) ($item['is_filter'] ?? false), 'position' => $position,
                ]);
            }
            $category->touch();
        });
        $this->audit->record('category.attributes_updated', ['attributes' => count($items)], $category, null, $actor);
    }

    /** Adds a set's attributes the category does not have yet, at the end. */
    public function applySet(Category $category, AttributeSet $set, User $actor): void
    {
        $current = $this->own($category)->map(fn (CategoryAttribute $ca) => ['attribute_id' => $ca->attribute_id, 'is_required' => $ca->is_required, 'is_filter' => $ca->is_filter])->values()->all();
        $have = array_column($current, 'attribute_id');
        foreach ($set->attributes as $attribute) {
            if (! in_array($attribute->id, $have, true)) {
                $current[] = ['attribute_id' => $attribute->id, 'is_required' => false, 'is_filter' => $attribute->type->isFilterable() && $attribute->type !== \App\Domain\Catalog\Models\AttributeType::Numeric];
            }
        }
        $this->set($category, $current, $actor);
    }

    /** @return Collection<int, CategoryAttribute> the category's own list, in order */
    public function own(Category $category): Collection
    {
        return CategoryAttribute::query()->where('category_id', $category->id)->with('attribute.values')->orderBy('position')->get();
    }

    /**
     * The list that applies: the category's own, or its nearest parent's.
     *
     * @return Collection<int, CategoryAttribute>
     */
    public function effective(Category $category): Collection
    {
        for ($c = $category, $depth = 0; $c !== null && $depth < 10; $c = $c->parent_id !== null ? Category::query()->find($c->parent_id) : null, $depth++) {
            $own = $this->own($c);
            if ($own->isNotEmpty()) {
                return $own->filter(fn (CategoryAttribute $ca) => $ca->attribute !== null && $ca->attribute->is_active)->values();
            }
        }

        return new Collection();
    }
}
