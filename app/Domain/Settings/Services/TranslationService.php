<?php

declare(strict_types=1);

namespace App\Domain\Settings\Services;

use App\Domain\Settings\Models\ContentTranslation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase B38 — Module 06 §101, Module 07 §99: translations of store content,
 * and the in-request lookup the storefront uses (Module 35 §4.3 "locale
 * fallback": a field without a translation shows the original).
 *
 * Only the fields listed here are translatable; text is stored as text and
 * rendered as text. Lookups are batched: prime() loads the translations of
 * many items with one query, so a product list costs one query, not one per
 * product.
 */
final class TranslationService
{
    /** type => field => maximum length */
    public const FIELDS = [
        'product' => ['name' => 255, 'short_description' => 500, 'description' => 20000],
        'category' => ['name' => 255, 'description' => 5000],
        'brand' => ['name' => 255, 'description' => 5000],
        'collection' => ['name' => 120, 'description' => 2000], // Phase B39
        // Phase B42 (Module 07 §38): saved through AttributeTranslationController, which checks the attribute.
        'attribute' => ['name' => 255],
        'attribute_value' => ['value' => 255],
    ];

    /** @var array<string, array<int, array<string, string>>> "type:locale" => id => field => value */
    private array $loaded = [];

    /** @return array<string, array<string, string>> locale => field => value, for one item */
    public function forItem(string $type, int $id): array
    {
        $out = [];
        foreach (ContentTranslation::query()->where('translatable_type', $type)->where('translatable_id', $id)->get() as $row) {
            $out[$row->locale][$row->field] = $row->value;
        }

        return $out;
    }

    /**
     * Replaces the translation of one item in one language: an empty field
     * removes that field's translation.
     *
     * @param array<string, mixed> $fields
     */
    public function save(string $type, int $id, string $locale, array $fields, ?int $userId): void
    {
        $allowed = self::FIELDS[$type] ?? throw ValidationException::withMessages(['type' => 'This kind of item cannot be translated.']);
        if (! Locales::isSupported($locale)) {
            throw ValidationException::withMessages(['locale' => 'Unknown language.']);
        }
        foreach ($fields as $field => $value) {
            if (! isset($allowed[$field])) {
                throw ValidationException::withMessages(["fields.{$field}" => 'This field cannot be translated.']);
            }
            if ($value !== null && (! is_string($value) || mb_strlen($value) > $allowed[$field])) {
                throw ValidationException::withMessages(["fields.{$field}" => "At most {$allowed[$field]} characters."]);
            }
        }

        DB::transaction(function () use ($type, $id, $locale, $fields, $userId) {
            foreach ($fields as $field => $value) {
                $match = ['translatable_type' => $type, 'translatable_id' => $id, 'locale' => $locale, 'field' => $field];
                if ($value === null || trim((string) $value) === '') {
                    // One by one, so the storefront cache hears of it (model events).
                    ContentTranslation::query()->where($match)->get()->each->delete();
                } else {
                    ContentTranslation::query()->updateOrCreate($match, ['value' => trim((string) $value), 'updated_by_user_id' => $userId]);
                }
            }
        });
        unset($this->loaded["{$type}:{$locale}"]);
    }

    /**
     * Loads the translations of these items in this language for later
     * value() calls.
     *
     * @param iterable<int> $ids
     */
    public function prime(string $type, iterable $ids, string $locale): void
    {
        $key = "{$type}:{$locale}";
        $missing = array_values(array_diff(array_unique(is_array($ids) ? $ids : iterator_to_array($ids, false)), array_keys($this->loaded[$key] ?? [])));
        if ($missing === []) {
            return;
        }
        foreach ($missing as $id) {
            $this->loaded[$key][$id] ??= [];
        }
        foreach (ContentTranslation::query()->where('translatable_type', $type)->where('locale', $locale)->whereIn('translatable_id', $missing)->get(['translatable_id', 'field', 'value']) as $row) {
            $this->loaded[$key][$row->translatable_id][$row->field] = $row->value;
        }
    }

    /** The translated value, or the original when there is none (or when the language is the original's). */
    public function value(string $type, int $id, string $field, ?string $original, string $locale, string $defaultLocale): ?string
    {
        if ($locale === $defaultLocale) {
            return $original;
        }
        $this->prime($type, [$id], $locale);

        return $this->loaded["{$type}:{$locale}"][$id][$field] ?? $original;
    }
}
