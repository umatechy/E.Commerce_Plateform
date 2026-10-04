<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Attribute;
use App\Domain\Catalog\Models\AttributeSet;
use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase B41 — Module 07 §28, §33, §35–37, §44, §75–79: changing an
 * attribute and its values, and attribute sets.
 *
 * Values are given as the full ordered list. A value no longer listed is
 * removed only when no product uses it; otherwise it is made inactive
 * (§76: "deactivated, not deleted, while in use"). The type of an
 * attribute never changes once products use it.
 */
final class AttributeManager
{
    public const MAX_VALUES = 200;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param array<string, mixed> $data name, group, unit, is_active, sort_order, type, values
     */
    public function update(Attribute $attribute, array $data, User $actor): Attribute
    {
        return DB::transaction(function () use ($attribute, $data, $actor) {
            if (isset($data['type']) && $data['type'] !== $attribute->type->value
                && DB::table('product_attribute_values')->where('attribute_id', $attribute->id)->exists()) {
                throw ValidationException::withMessages(['type' => 'Products already use this attribute; its type cannot change.']);
            }
            $attribute->update(array_intersect_key($data, array_flip(['name', 'type', 'group', 'unit', 'is_active', 'sort_order'])));
            if (array_key_exists('values', $data)) {
                $this->syncValues($attribute, (array) $data['values']);
            }
            $attribute->touch(); // values have no store id: the attribute carries the storefront cache bump
            $this->audit->record('attribute.updated', ['key' => $attribute->key], $attribute, null, $actor);

            return $attribute->refresh()->load('values');
        });
    }

    /**
     * @param array<int, mixed> $values strings, or {id?, value, color_code?, is_active?}
     */
    public function syncValues(Attribute $attribute, array $values): void
    {
        if (count($values) > self::MAX_VALUES) {
            throw ValidationException::withMessages(['values' => 'At most '.self::MAX_VALUES.' values.']);
        }
        $existing = $attribute->values()->get()->keyBy('id');
        $keep = [];
        $seen = [];
        foreach (array_values($values) as $position => $item) {
            $item = is_string($item) ? ['value' => $item] : (is_array($item) ? $item : []);
            $text = trim((string) ($item['value'] ?? ''));
            $normalized = Str::lower($text);
            if ($text === '' || mb_strlen($text) > 255) {
                throw ValidationException::withMessages(["values.{$position}" => 'Each value is 1 to 255 characters.']);
            }
            if (isset($seen[$normalized])) {
                throw ValidationException::withMessages(["values.{$position}" => "\"{$text}\" is listed twice."]);
            }
            $seen[$normalized] = true;
            $color = $item['color_code'] ?? null;
            if ($color !== null && $color !== '' && preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $color) !== 1) {
                throw ValidationException::withMessages(["values.{$position}.color_code" => 'A colour code looks like #1A2B3C.']);
            }
            $fields = [
                'value' => $text, 'normalized_value' => $normalized, 'sort_order' => $position,
                'color_code' => $color === '' || $color === null ? null : strtoupper((string) $color),
                'is_active' => (bool) ($item['is_active'] ?? true),
            ];
            $current = isset($item['id']) ? $existing->get((int) $item['id']) : $existing->first(fn (AttributeValue $v) => $v->normalized_value === $normalized);
            if ($current !== null) {
                $current->update($fields);
                $keep[] = $current->id;
            } else {
                $keep[] = $attribute->values()->create([...$fields, 'slug' => $this->slug($attribute, $text)])->id;
            }
        }

        $last = count($values);
        foreach ($existing->except($keep) as $gone) {
            /** @var AttributeValue $gone */
            if (DB::table('product_attribute_values')->where('attribute_value_id', $gone->id)->exists()) {
                // In use: kept (after the listed ones), hidden from new choices and filters.
                $gone->update(['is_active' => false, 'sort_order' => $last++]);
            } else {
                $gone->delete();
            }
        }
    }

    /**
     * @param list<int> $attributeIds in order
     */
    public function saveSet(?AttributeSet $set, string $name, array $attributeIds, User $actor): AttributeSet
    {
        $ids = array_values(array_unique(array_map('intval', $attributeIds)));
        if (Attribute::query()->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['attributes' => 'One of the attributes is not in this store.']);
        }

        return DB::transaction(function () use ($set, $name, $ids, $actor) {
            $set ??= new AttributeSet();
            $set->fill(['name' => $name])->save();
            $set->attributes()->sync(collect($ids)->mapWithKeys(fn (int $id, int $position) => [$id => ['position' => $position]])->all());
            $this->audit->record('attribute_set.saved', ['name' => $name, 'attributes' => count($ids)], $set, null, $actor);

            return $set->load('attributes');
        });
    }

    private function slug(Attribute $attribute, string $text): string
    {
        $base = Str::slug($text) ?: 'v'.substr(sha1($text), 0, 8);
        $slug = $base;
        for ($i = 2; $attribute->values()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
