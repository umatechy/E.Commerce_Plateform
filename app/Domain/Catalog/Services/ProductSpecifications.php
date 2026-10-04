<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Attribute;
use App\Domain\Catalog\Models\AttributeType;
use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase B41 — Module 07 §41, §45: a product's specifications, validated by
 * the attribute's type:
 *
 *   select / colour   one value id of the attribute (active)
 *   multi_select      a list of value ids
 *   numeric           a number (stored with 4 decimals)
 *   boolean           true / false
 *   text              up to 500 characters
 *
 * Saving replaces the product's specifications with the given list; a
 * required attribute of the product's main category (or of the category
 * whose list it follows) must have a value.
 */
final class ProductSpecifications
{
    public const MAX_ATTRIBUTES = 60;

    public function __construct(private readonly CategoryAttributes $categories, private readonly AuditLogger $audit) {}

    /**
     * @return list<array{attribute_id: int, value: mixed}>
     */
    public function values(Product $product): array
    {
        $out = [];
        $rows = ProductAttributeValue::query()->where('product_id', $product->id)->with('attribute')->orderBy('id')->get();
        foreach ($rows->groupBy('attribute_id') as $attributeId => $group) {
            $attribute = $group->first()->attribute;
            if ($attribute === null) {
                continue;
            }
            $out[] = ['attribute_id' => (int) $attributeId, 'value' => match ($attribute->type) {
                AttributeType::MultiSelect => $group->pluck('attribute_value_id')->map(fn ($id) => (int) $id)->values()->all(),
                AttributeType::Select, AttributeType::Color => (int) $group->first()->attribute_value_id,
                AttributeType::Numeric => (float) $group->first()->number_value,
                AttributeType::Boolean => (bool) $group->first()->bool_value,
                AttributeType::Text => (string) $group->first()->text_value,
            }];
        }

        return $out;
    }

    /** @return list<int> ids of the attributes required for this product */
    public function requiredFor(Product $product): array
    {
        $category = $product->primary_category_id !== null ? Category::query()->find($product->primary_category_id) : null;

        return $category === null ? [] : $this->categories->effective($category)->where('is_required', true)->pluck('attribute_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * @param array<int, array<string, mixed>> $items {attribute_id, value}
     */
    public function save(Product $product, array $items, ?User $actor): void
    {
        if (count($items) > self::MAX_ATTRIBUTES) {
            throw ValidationException::withMessages(['specifications' => 'At most '.self::MAX_ATTRIBUTES.' specifications.']);
        }
        $ids = array_map(fn ($i) => (int) ($i['attribute_id'] ?? 0), $items);
        if (count(array_unique($ids)) !== count($ids)) {
            throw ValidationException::withMessages(['specifications' => 'An attribute is listed twice.']);
        }
        $attributes = Attribute::query()->whereIn('id', $ids)->with('values')->get()->keyBy('id'); // tenant-scoped

        $rows = [];
        $filled = [];
        foreach (array_values($items) as $i => $item) {
            $attribute = $attributes->get((int) $item['attribute_id']);
            if ($attribute === null) {
                throw ValidationException::withMessages(["specifications.{$i}" => 'This attribute is not in this store.']);
            }
            $value = $item['value'] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue; // no value: the specification is removed
            }
            foreach ($this->rows($attribute, $value, "specifications.{$i}.value") as $row) {
                $rows[] = ['attribute_id' => $attribute->id, ...$row];
            }
            $filled[] = $attribute->id;
        }

        $missing = array_diff($this->requiredFor($product), $filled);
        if ($missing !== []) {
            $names = Attribute::query()->whereIn('id', $missing)->pluck('name')->implode(', ');
            throw ValidationException::withMessages(['specifications' => "Required for this product's category: {$names}."]);
        }

        DB::transaction(function () use ($product, $rows) {
            ProductAttributeValue::query()->where('product_id', $product->id)->delete();
            foreach ($rows as $row) {
                ProductAttributeValue::query()->create(['product_id' => $product->id, ...$row]);
            }
            $product->touch(); // the storefront cache follows the product
        });
        $this->audit->record('product.specifications_updated', ['attributes' => count($filled)], $product, null, $actor);
    }

    /**
     * @return list<array<string, mixed>> the rows for one attribute's value
     */
    private function rows(Attribute $attribute, mixed $value, string $field): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages([$field => "{$attribute->name}: {$message}"]);
        $choice = function (mixed $id) use ($attribute, $fail): AttributeValue {
            $found = is_int($id) ? $attribute->values->first(fn (AttributeValue $v) => $v->id === $id) : null;
            if ($found === null) {
                $fail('choose one of its values.');
            }
            if (! $found->is_active) {
                $fail("\"{$found->value}\" is no longer offered.");
            }

            return $found;
        };

        return match ($attribute->type) {
            AttributeType::Select, AttributeType::Color => [['attribute_value_id' => $choice($value)->id]],
            AttributeType::MultiSelect => is_array($value) && array_is_list($value) && count($value) <= 30
                ? array_map(fn ($id) => ['attribute_value_id' => $choice($id)->id], array_values(array_unique($value, SORT_REGULAR)))
                : $fail('choose up to 30 of its values.'),
            AttributeType::Numeric => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
                ? (abs((float) $value) < 1e13 ? [['number_value' => round((float) $value, 4)]] : $fail('the number is too large.'))
                : $fail('give a number.'),
            AttributeType::Boolean => is_bool($value) ? [['bool_value' => $value]] : $fail('yes or no.'),
            AttributeType::Text => is_string($value) && mb_strlen(trim($value)) <= 500 ? [['text_value' => trim($value)]] : $fail('up to 500 characters.'),
        };
    }
}
