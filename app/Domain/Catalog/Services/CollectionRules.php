<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Tag;
use Illuminate\Validation\ValidationException;

/**
 * Phase B39 — Module 06 §34 "rule-based collections": the conditions a
 * product must meet. A fixed list of fields — never a column name from the
 * request (Module 07 §49 "no arbitrary database field access"):
 *
 *   category  [ids]   in one of these categories (or their children)
 *   brand     [ids]   of one of these brands
 *   tag       [ids]   with one of these tags
 *   price_min  int    price (minor units) at least
 *   price_max  int    price (minor units) at most
 *   on_sale    true   has a sale price
 *   in_stock   true   can be bought now
 *   featured   true   marked as featured
 *   new_within_days int  published within the last N days
 *
 * `match`: all conditions, or any of them. Ids must be of the store.
 */
final class CollectionRules
{
    public const FIELDS = ['category', 'brand', 'tag', 'price_min', 'price_max', 'on_sale', 'in_stock', 'featured', 'new_within_days'];

    public const MAX_CONDITIONS = 10;

    /**
     * @return list<array{field: string, value: mixed}>
     *
     * @throws ValidationException
     */
    public function validate(mixed $rules): array
    {
        if (! is_array($rules) || ! array_is_list($rules) || $rules === [] || count($rules) > self::MAX_CONDITIONS) {
            throw ValidationException::withMessages(['rules' => 'A rule-based collection needs between 1 and '.self::MAX_CONDITIONS.' conditions.']);
        }

        $clean = [];
        foreach ($rules as $i => $rule) {
            $field = is_array($rule) ? ($rule['field'] ?? null) : null;
            if (! in_array($field, self::FIELDS, true) || array_diff(array_keys($rule), ['field', 'value']) !== []) {
                throw ValidationException::withMessages(["rules.{$i}" => 'Unknown condition.']);
            }
            $value = $rule['value'] ?? null;
            $clean[] = ['field' => $field, 'value' => match ($field) {
                'category' => $this->ids($value, Category::class, $i),
                'brand' => $this->ids($value, Brand::class, $i),
                'tag' => $this->ids($value, Tag::class, $i),
                'price_min', 'price_max' => $this->integer($value, 0, 100_000_000_000, $i),
                'new_within_days' => $this->integer($value, 1, 3650, $i),
                default => $this->true($value, $i),
            }];
        }

        return $clean;
    }

    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model> $model
     * @return list<int>
     */
    private function ids(mixed $value, string $model, int $i): array
    {
        if (! is_array($value) || $value === [] || count($value) > 100 || array_filter($value, fn ($id) => ! is_int($id)) !== []) {
            throw ValidationException::withMessages(["rules.{$i}.value" => 'Choose at least one.']);
        }
        $ids = array_values(array_unique($value));
        // Tenant-scoped models: another store's id simply does not count.
        if ($model::query()->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(["rules.{$i}.value" => 'One of the chosen items is not in this store.']);
        }

        return $ids;
    }

    private function integer(mixed $value, int $min, int $max, int $i): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            throw ValidationException::withMessages(["rules.{$i}.value" => "A whole number from {$min} to {$max}."]);
        }

        return $value;
    }

    private function true(mixed $value, int $i): bool
    {
        if ($value !== true) {
            throw ValidationException::withMessages(["rules.{$i}.value" => 'This condition is either on (true) or left out.']);
        }

        return true;
    }
}
