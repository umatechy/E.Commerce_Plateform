<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Attribute;
use App\Domain\Catalog\Models\AttributeSet;
use App\Domain\Catalog\Models\AttributeType;
use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\CategoryAttribute;
use App\Domain\Catalog\Models\StarterTemplateApplication;
use App\Domain\Catalog\Support\StarterTemplates;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Models\StoreSetting;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreStatus;
use App\Domain\Theme\Exceptions\InvalidThemeConfigException;
use App\Domain\Theme\Exceptions\ThemeNotEntitledException;
use App\Domain\Theme\Models\StoreTheme;
use App\Domain\Theme\Services\ThemeEntitlements;
use App\Domain\Theme\Services\ThemeService;
use App\Domain\Theme\Support\ThemeCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase B45 — Module 07 §20, §101, §103, §105–106; Module 03 §57–58: a
 * starter template copied into the CURRENT store's own data.
 *
 * Only ever adds (§103: nothing is deleted, merged away or re-typed):
 *
 * - a category with the same name under the same parent is the store's
 *   own and is kept as it is; only missing ones are created;
 * - an attribute with the same key is kept: missing values are added at
 *   the end; one of another type is left alone (reported as "kept");
 * - a category's attribute list gains the missing attributes, its own
 *   flags stay;
 * - brands only when asked; a brand with the same name is kept;
 * - the theme only when asked, and only one the package includes (the
 *   first of the template's choices, else Classic). A store that is not
 *   live yet gets it published; a live store gets it in the draft for the
 *   owner to look at and publish (Module 17 §19);
 * - the default product order only when the store has not chosen its own.
 *
 * One transaction with the store row locked, so two clicks never create a
 * category twice; one history row, an audit entry and an outbox event.
 *
 * The caller sets the tenant context (the request's store, or
 * TenantContext::asStore() during provisioning).
 */
final class StarterTemplateService
{
    public function __construct(
        private readonly AttributeManager $attributes,
        private readonly CategoryAttributes $categoryAttributes,
        private readonly ThemeService $themes,
        private readonly ThemeEntitlements $themeEntitlements,
        private readonly ConfigService $config,
        private readonly AuditLogger $audit,
        private readonly RecordsOutboxEvents $outbox,
    ) {}

    /**
     * What the template would add, marked against what the store has.
     *
     * @return array<string, mixed>
     */
    public function preview(string $key): array
    {
        $template = StarterTemplates::get($key);
        $existing = Attribute::query()->get()->keyBy('key');
        $brands = Brand::query()->pluck('name')->map(fn (string $n) => Str::lower($n))->all();
        $theme = $this->themeFor($template);

        return [
            'key' => $key,
            'name' => $template['name'],
            'version' => $template['version'],
            'summary' => $template['summary'],
            'categories' => array_map(function (array $category) use ($template) {
                $own = $this->findCategory($category['name'], null);

                return [
                    'name' => $category['name'],
                    'description' => $category['description'] ?? null,
                    'exists' => $own !== null,
                    'children' => array_map(fn (string $child) => ['name' => $child, 'exists' => $own !== null && $this->findCategory($child, $own->id) !== null], $category['children'] ?? []),
                    'attributes' => $this->attributeList($template, $category),
                ];
            }, $template['categories']),
            'attributes' => array_map(function (array $def) use ($existing) {
                /** @var Attribute|null $own */
                $own = $existing->get($def['key']);

                return [
                    'key' => $def['key'],
                    'name' => $def['name'],
                    'type' => $def['type'],
                    'unit' => $def['unit'] ?? null,
                    'values' => array_map(fn ($v) => is_array($v) ? $v[0] : $v, $def['values'] ?? []),
                    'exists' => $own !== null,
                    'kept' => $own !== null && $own->type->value !== $def['type'],
                ];
            }, $template['attributes']),
            'brands' => array_map(fn (string $name) => ['name' => $name, 'exists' => in_array(Str::lower($name), $brands, true)], $template['brands'] ?? []),
            'theme' => ['key' => $theme, 'name' => ThemeCatalog::get($theme)['name'], 'preferred' => $theme === $template['themes'][0]],
            'default_sort' => $template['default_sort'],
            'default_sort_is_set' => $this->storeChoseSort(),
        ];
    }

    /**
     * @param array{brands?: bool, theme?: bool, default_sort?: bool} $options
     * @return array<string, mixed> what was added
     */
    public function apply(Store $store, string $key, User $actor, array $options = []): array
    {
        $template = StarterTemplates::get($key);

        return DB::transaction(function () use ($store, $key, $template, $actor, $options) {
            Store::query()->whereKey($store->id)->lockForUpdate()->first();
            $summary = [
                'categories_added' => 0, 'categories_kept' => 0, 'attributes_added' => 0, 'values_added' => 0,
                'attributes_kept' => [], 'category_attributes_added' => 0, 'brands_added' => 0, 'theme' => null, 'default_sort' => null,
            ];

            $byKey = $this->ensureAttributes($template, $summary);
            $this->ensureSet($template['name'], $byKey, $actor);
            $this->ensureCategories($template, $byKey, $actor, $summary);
            if ($options['brands'] ?? false) {
                $this->ensureBrands($template['brands'] ?? [], $summary);
            }
            if ($options['theme'] ?? false) {
                $summary['theme'] = $this->applyTheme($store, $template, $actor);
            }
            if (($options['default_sort'] ?? false) && ! $this->storeChoseSort()) {
                $this->config->set('catalog.default_sort', $template['default_sort'], SettingScope::Store, $actor->id, "Starter template: {$template['name']}");
                $summary['default_sort'] = $template['default_sort'];
            }

            $application = StarterTemplateApplication::query()->create([
                'store_id' => $store->id, 'template_key' => $key, 'template_version' => $template['version'],
                'applied_by_user_id' => $actor->id, 'summary' => $summary,
            ]);
            $this->audit->record('catalog.starter_template_applied', ['template' => $key, 'version' => $template['version'], ...$summary], $application, $store->id, $actor);
            $this->outbox->recordEventFor($store->id, 'catalog.starter_template_applied', ['template' => $key, 'version' => $template['version']], "starter_template:{$application->id}");

            return $summary;
        });
    }

    /** @return list<array{key: string, version: int, applied_at: string, applied_by_user_id: int|null}> newest first */
    public function history(): array
    {
        return StarterTemplateApplication::query()->latest('id')->limit(20)->get()
            ->map(fn (StarterTemplateApplication $a) => ['key' => $a->template_key, 'version' => $a->template_version, 'applied_at' => $a->created_at->toIso8601String(), 'applied_by_user_id' => $a->applied_by_user_id])
            ->values()->all();
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $summary
     * @return array<string, Attribute> the template's attributes in the store, by key
     */
    private function ensureAttributes(array $template, array &$summary): array
    {
        $byKey = [];
        $order = (int) Attribute::query()->max('sort_order');
        foreach ($template['attributes'] as $def) {
            $own = Attribute::query()->where('key', $def['key'])->first();
            if ($own === null) {
                $own = Attribute::query()->create([
                    'name' => $def['name'], 'key' => $def['key'], 'type' => $def['type'],
                    'group' => $def['group'] ?? null, 'unit' => $def['unit'] ?? null, 'sort_order' => ++$order,
                ]);
                $this->attributes->syncValues($own, array_map(fn ($v) => is_array($v) ? ['value' => $v[0], 'color_code' => $v[1]] : $v, $def['values'] ?? []));
                $summary['attributes_added']++;
            } elseif ($own->type->value !== $def['type']) {
                $summary['attributes_kept'][] = $def['key']; // the store's own attribute of another type: left alone
            } elseif ($own->type->hasValues() && ($def['values'] ?? []) !== []) {
                $summary['values_added'] += $this->addMissingValues($own, $def['values']);
            }
            $byKey[$def['key']] = $own;
        }

        return $byKey;
    }

    /** @param list<string|array{0: string, 1: string}> $values */
    private function addMissingValues(Attribute $attribute, array $values): int
    {
        $current = $attribute->values()->get();
        $have = $current->map(fn (AttributeValue $v) => $v->normalized_value)->all();
        $missing = array_values(array_filter($values, fn ($v) => ! in_array(Str::lower(trim(is_array($v) ? $v[0] : $v)), $have, true)));
        if ($missing === []) {
            return 0;
        }
        $list = $current->map(fn (AttributeValue $v) => ['id' => $v->id, 'value' => $v->value, 'color_code' => $v->color_code, 'is_active' => $v->is_active])->all();
        foreach ($missing as $v) {
            $list[] = is_array($v) ? ['value' => $v[0], 'color_code' => $attribute->type === AttributeType::Color ? $v[1] : null] : ['value' => $v];
        }
        $this->attributes->syncValues($attribute, $list);
        $attribute->touch(); // values carry no store id: the attribute carries the storefront cache bump

        return count($missing);
    }

    /** @param array<string, Attribute> $byKey */
    private function ensureSet(string $name, array $byKey, User $actor): void
    {
        $set = AttributeSet::query()->where('name', $name)->first();
        $ids = $set?->attributes()->pluck('attributes.id')->map(fn ($id) => (int) $id)->all() ?? [];
        $new = array_values(array_diff(array_map(fn (Attribute $a) => $a->id, $byKey), $ids));
        if ($set === null || $new !== []) {
            $this->attributes->saveSet($set, $name, [...$ids, ...$new], $actor);
        }
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, Attribute> $byKey
     * @param array<string, mixed> $summary
     */
    private function ensureCategories(array $template, array $byKey, User $actor, array &$summary): void
    {
        $order = (int) Category::query()->whereNull('parent_id')->max('sort_order');
        foreach ($template['categories'] as $def) {
            $category = $this->findCategory($def['name'], null);
            if ($category === null) {
                $category = $this->createCategory($def['name'], $def['description'] ?? null, null, ++$order);
                $summary['categories_added']++;
            } else {
                $summary['categories_kept']++;
            }
            foreach ($def['children'] ?? [] as $i => $child) {
                if ($this->findCategory($child, $category->id) === null) {
                    $this->createCategory($child, null, $category->id, $i);
                    $summary['categories_added']++;
                } else {
                    $summary['categories_kept']++;
                }
            }
            $summary['category_attributes_added'] += $this->addCategoryAttributes($category, $def['attributes'] ?? [], $byKey, $actor);
        }
    }

    /**
     * @param array<string, string> $wanted key => flags
     * @param array<string, Attribute> $byKey
     */
    private function addCategoryAttributes(Category $category, array $wanted, array $byKey, User $actor): int
    {
        $current = $this->categoryAttributes->own($category)
            ->map(fn (CategoryAttribute $ca) => ['attribute_id' => $ca->attribute_id, 'is_required' => $ca->is_required, 'is_filter' => $ca->is_filter])
            ->values()->all();
        $have = array_column($current, 'attribute_id');
        $added = 0;
        foreach ($wanted as $key => $spec) {
            $attribute = $byKey[$key] ?? null;
            if ($attribute === null || in_array($attribute->id, $have, true) || count($current) >= CategoryAttributes::MAX_PER_CATEGORY) {
                continue;
            }
            $flags = StarterTemplates::flags($spec);
            $current[] = [
                'attribute_id' => $attribute->id,
                'is_required' => in_array('required', $flags, true),
                'is_filter' => in_array('filter', $flags, true) && $attribute->type->isFilterable(),
            ];
            $added++;
        }
        if ($added > 0) {
            $this->categoryAttributes->set($category, $current, $actor);
        }

        return $added;
    }

    /**
     * @param list<string> $names
     * @param array<string, mixed> $summary
     */
    private function ensureBrands(array $names, array &$summary): void
    {
        foreach ($names as $name) {
            if (! Brand::query()->whereRaw('LOWER(name) = ?', [Str::lower($name)])->exists()) {
                Brand::query()->create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6))]);
                $summary['brands_added']++;
            }
        }
    }

    /**
     * @param array<string, mixed> $template
     * @return array{key: string, name: string, published: bool}|null
     */
    private function applyTheme(Store $store, array $template, User $actor): ?array
    {
        $storeTheme = StoreTheme::query()->where('store_id', $store->id)->first();
        if ($storeTheme === null) {
            return null;
        }
        $key = $this->themeFor($template);
        if (($storeTheme->draft_config['theme'] ?? null) === $key && ($storeTheme->published_config['theme'] ?? null) === $key) {
            return ['key' => $key, 'name' => ThemeCatalog::get($key)['name'], 'published' => true]; // already on it: nothing to reset
        }
        try {
            $storeTheme = $this->themes->selectTheme($storeTheme, $key, $actor->id);
            // A store nobody can visit yet gets the look at once; a live store's owner decides when (Module 17 §19).
            $publish = $store->fresh()?->status !== StoreStatus::Active;
            if ($publish) {
                $this->themes->publish($storeTheme, $actor->id);
            }
        } catch (ThemeNotEntitledException|InvalidThemeConfigException) {
            return null; // e.g. sections the package no longer includes: the theme is left as it is
        }

        return ['key' => $key, 'name' => ThemeCatalog::get($key)['name'], 'published' => $publish];
    }

    /** @param array<string, mixed> $template the first of the template's themes the package includes */
    private function themeFor(array $template): string
    {
        foreach ($template['themes'] as $key) {
            if (ThemeCatalog::has($key) && $this->themeEntitlements->allows(ThemeCatalog::requiredFeature($key))) {
                return $key;
            }
        }

        return ThemeCatalog::DEFAULT;
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $category
     * @return list<array{key: string, name: string, filter: bool, required: bool}>
     */
    private function attributeList(array $template, array $category): array
    {
        $names = array_column($template['attributes'], 'name', 'key');
        $types = array_column($template['attributes'], 'type', 'key');
        $list = [];
        foreach ($category['attributes'] ?? [] as $key => $spec) {
            $flags = StarterTemplates::flags($spec);
            $list[] = ['key' => $key, 'name' => $names[$key] ?? $key, 'filter' => in_array('filter', $flags, true) && $types[$key] !== 'text', 'required' => in_array('required', $flags, true)];
        }

        return $list;
    }

    private function findCategory(string $name, ?int $parentId): ?Category
    {
        return Category::query()
            ->when($parentId === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parentId))
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->first();
    }

    private function createCategory(string $name, ?string $description, ?int $parentId, int $order): Category
    {
        return Category::query()->create([
            'name' => $name,
            'slug' => (Str::slug($name) ?: 'category').'-'.Str::lower(Str::random(6)),
            'description' => $description,
            'parent_id' => $parentId,
            'status' => 'active',
            'visibility' => 'public',
            'sort_order' => $order,
        ]);
    }

    /** Whether the store has chosen its own default order (a template never overrides it). */
    private function storeChoseSort(): bool
    {
        return StoreSetting::query()->where('key', 'catalog.default_sort')->exists();
    }
}
