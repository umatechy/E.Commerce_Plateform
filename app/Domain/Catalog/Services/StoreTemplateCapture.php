<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Attribute;
use App\Domain\Catalog\Models\AttributeType;
use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\CategoryAttribute;
use App\Domain\Catalog\Models\PlatformStarterTemplate;
use App\Domain\Catalog\Support\StarterTemplates;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Models\ContentTranslation;
use App\Domain\Settings\Models\StoreSetting;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\BusinessCategories;
use App\Domain\Tenancy\Support\TenantContext;
use App\Domain\Theme\Models\StoreTheme;
use App\Domain\Theme\Support\ThemeCatalog;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase B45 follow-up — Module 03 §52 "Store template / cloning concept"
 * (a controlled workflow), Module 07 §101: Umar Techy staff save the
 * STRUCTURE of an existing store as a starter template, to start other
 * stores from it (e.g. a bakery set up by the team becomes the start of the
 * next bakery). New stores then come from the template through the normal
 * creation path, so tenant ids, packages, subscriptions and domains are
 * always their own (§52).
 *
 * Copied: categories (two levels: a top-level category and its direct
 * sub-categories; deeper ones are counted and reported, not copied), their
 * descriptions, active attributes and their active values (colours with
 * their codes), which attributes the top-level categories use as filters or
 * required fields, brand names, the published theme's key, the default
 * product order, and the Urdu text of all of these.
 *
 * Never copied (§52): products, prices, stock, orders, customers, reviews,
 * team members, pictures and logos, pages and policies, domains, payment and
 * shipping settings, credentials or any other setting.
 *
 * A saved template starts switched on for staff and NOT offered to store
 * owners; staff decide to offer it (it may carry brand or category names of
 * the source store).
 */
final class StoreTemplateCapture
{
    public function __construct(private readonly TenantContext $tenant, private readonly AuditLogger $audit) {}

    /**
     * @return array{template: PlatformStarterTemplate, skipped_deeper_categories: int}
     */
    public function save(Store $store, User $staff, string $name, ?string $summary, ?string $businessCategory): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => 'Give the template a name of up to 120 characters.']);
        }
        if ($businessCategory !== null && ! in_array($businessCategory, BusinessCategories::keys(), true)) {
            throw ValidationException::withMessages(['business_category' => 'Choose what the stores sell from the list.']);
        }

        [$definition, $skipped] = $this->tenant->asStore($store->id, fn () => $this->capture());
        if ($definition['categories'] === [] && $definition['attributes'] === []) {
            throw ValidationException::withMessages(['store' => 'This store has no categories or attributes to save.']);
        }
        $problems = StarterTemplates::problems($definition, builtIn: false);
        if ($problems !== []) {
            throw ValidationException::withMessages(['store' => $problems[0]]);
        }

        $template = PlatformStarterTemplate::query()->create([
            'key' => $this->key($name),
            'source' => PlatformStarterTemplate::FROM_STORE,
            'name' => $name,
            'summary' => $summary !== null && trim($summary) !== '' ? mb_substr(trim($summary), 0, 500) : "Saved from {$store->name}.",
            'business_category' => $businessCategory ?? $store->business_category,
            'definition' => $definition,
            'is_active' => true,
            'offered_to_stores' => false,
            'source_store_id' => $store->id,
            'created_by_user_id' => $staff->id,
        ]);
        $this->audit->record('starter_template.saved_from_store', [
            'key' => $template->key, 'source_store_id' => $store->id,
            'categories' => count($definition['categories']), 'attributes' => count($definition['attributes']), 'skipped_deeper_categories' => $skipped,
        ], $template, null, $staff);

        return ['template' => $template, 'skipped_deeper_categories' => $skipped];
    }

    /** @return array{0: array<string, mixed>, 1: int} the definition, and how many deeper categories were left out */
    private function capture(): array
    {
        $ur = [];
        $translations = ContentTranslation::query()->where('locale', 'ur')
            ->whereIn('translatable_type', ['category', 'attribute', 'attribute_value'])->get()
            ->groupBy(fn (ContentTranslation $t) => "{$t->translatable_type}:{$t->translatable_id}");
        $urdu = function (string $type, int $id, string $field, ?string $english) use ($translations, &$ur): void {
            $row = ($translations->get("{$type}:{$id}") ?? collect())->firstWhere('field', $field);
            if ($english !== null && $english !== '' && $row !== null) {
                $ur[$english] = $row->value;
            }
        };

        $attributes = Attribute::query()->where('is_active', true)->with('values')->orderBy('sort_order')->orderBy('id')->get();
        $defs = [];
        foreach ($attributes as $attribute) {
            $def = ['key' => $attribute->key, 'name' => $attribute->name, 'type' => $attribute->type->value];
            if ($attribute->group !== null) {
                $def['group'] = $attribute->group;
            }
            if ($attribute->unit !== null) {
                $def['unit'] = $attribute->unit;
            }
            if ($attribute->type->hasValues()) {
                $def['values'] = $attribute->values->where('is_active', true)->values()->map(function (AttributeValue $v) use ($attribute, $urdu) {
                    $urdu('attribute_value', $v->id, 'value', $v->value);

                    return $attribute->type === AttributeType::Color ? [$v->value, $v->color_code] : $v->value;
                })->all();
            }
            $urdu('attribute', $attribute->id, 'name', $attribute->name);
            $defs[] = $def;
        }
        $keyById = $attributes->pluck('key', 'id');

        $tops = Category::query()->whereNull('parent_id')->where('status', '!=', 'archived')->orderBy('sort_order')->orderBy('id')->get();
        $categories = [];
        $skipped = 0;
        foreach ($tops as $top) {
            $children = Category::query()->where('parent_id', $top->id)->where('status', '!=', 'archived')->orderBy('sort_order')->orderBy('id')->get();
            foreach ($children as $child) {
                $urdu('category', $child->id, 'name', $child->name);
                $skipped += $this->descendants($child->id);
            }
            $flags = [];
            foreach (CategoryAttribute::query()->where('category_id', $top->id)->orderBy('position')->get() as $ca) {
                if (isset($keyById[$ca->attribute_id])) {
                    $flags[$keyById[$ca->attribute_id]] = implode(',', array_keys(array_filter(['filter' => $ca->is_filter, 'required' => $ca->is_required])));
                }
            }
            $urdu('category', $top->id, 'name', $top->name);
            $urdu('category', $top->id, 'description', $top->description);
            $categories[] = array_filter([
                'name' => $top->name, 'description' => $top->description,
                'attributes' => $flags, 'children' => $children->pluck('name')->unique(fn ($n) => mb_strtolower($n))->values()->all(),
            ], fn ($v) => $v !== null && $v !== []);
        }

        $published = StoreTheme::query()->first()?->published_config['theme'] ?? ThemeCatalog::DEFAULT;
        $sort = StoreSetting::query()->where('key', 'catalog.default_sort')->first()?->value[0] ?? 'newest';

        return [[
            'attributes' => $defs,
            'categories' => $categories,
            'brands' => Brand::query()->orderBy('name')->pluck('name')->unique(fn ($n) => mb_strtolower($n))->values()->all(),
            'themes' => array_values(array_unique([ThemeCatalog::has($published) ? $published : ThemeCatalog::DEFAULT, ThemeCatalog::DEFAULT])),
            'default_sort' => is_string($sort) ? $sort : 'newest',
            'ur' => $ur,
        ], $skipped];
    }

    /** How many categories sit below this one (they are not copied: a template has two levels). */
    private function descendants(int $id): int
    {
        $count = 0;
        $level = [$id];
        for ($depth = 0; $level !== [] && $depth < 10; $depth++) {
            $level = Category::query()->whereIn('parent_id', $level)->pluck('id')->all();
            $count += count($level);
        }

        return $count;
    }

    private function key(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_') ?: 'template', 24, '');
        do {
            $key = 'store_'.$base.'_'.Str::lower(Str::random(4));
        } while (PlatformStarterTemplate::query()->where('key', $key)->exists());

        return preg_replace('/[^a-z0-9_]/', '', $key) ?? $key;
    }
}
