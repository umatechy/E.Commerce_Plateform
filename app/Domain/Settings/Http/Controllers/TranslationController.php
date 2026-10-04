<?php

declare(strict_types=1);

namespace App\Domain\Settings\Http\Controllers;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Settings\Services\Locales;
use App\Domain\Settings\Services\TranslationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Phase B38 — translations of a product, category or brand
 * (Module 06 §101, Module 07 §99).
 *
 *   GET /api/v1/translations/{type}/{id}  — every language the store offers
 *   PUT /api/v1/translations/{type}/{id}  — {locale, fields: {name, …}}
 *
 * {id} is the product's public id, or the category's / brand's id as the
 * catalog API gives it. The item is looked up in the current store only;
 * changing a translation needs the same permission as changing the item.
 */
final class TranslationController
{
    public function show(Request $request, string $type, string $id, TranslationService $translations, ConfigService $config): JsonResponse
    {
        $item = $this->item($type, $id);
        $this->authorize($request, $type, $item, view: true);
        $default = (string) ($config->get('store.default_locale') ?? Locales::DEFAULT);

        return response()->json(['data' => [
            'default_locale' => $default,
            'languages' => Locales::describe(array_values(array_diff((array) $config->get('store.languages'), [$default]))),
            'fields' => array_keys(TranslationService::FIELDS[$type]),
            'original' => array_map(fn (string $field) => $item->getAttribute($field), array_combine(array_keys(TranslationService::FIELDS[$type]), array_keys(TranslationService::FIELDS[$type]))),
            'translations' => (object) $translations->forItem($type, (int) $item->getKey()),
        ]]);
    }

    public function update(Request $request, string $type, string $id, TranslationService $translations, AuditLogger $audit): JsonResponse
    {
        $item = $this->item($type, $id);
        $this->authorize($request, $type, $item, view: false);
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys(Locales::SUPPORTED))],
            'fields' => ['required', 'array'],
        ]);
        $translations->save($type, (int) $item->getKey(), $data['locale'], $data['fields'], $request->user()->id);
        $audit->record('translation.updated', ['type' => $type, 'locale' => $data['locale'], 'fields' => array_keys($data['fields'])], $item);

        return $this->show($request, $type, $id, $translations, app(ConfigService::class));
    }

    private function item(string $type, string $id): Model
    {
        $model = match ($type) {
            'product' => Product::class,
            'category' => Category::class,
            'brand' => Brand::class,
            'collection' => \App\Domain\Catalog\Models\Collection::class, // Phase B39
            default => abort(404),
        };
        $query = $model::query();

        return (strlen($id) === 26 ? $query->where('public_id', $id) : $query->whereKey((int) $id))->firstOrFail();
    }

    private function authorize(Request $request, string $type, Model $item, bool $view): void
    {
        $gate = Gate::forUser($request->user());
        match ($type) {
            'product' => $gate->authorize($view ? 'view' : 'update', $item),
            default => $view ? $gate->authorize('viewAny', $item::class) : $gate->authorize('manage', $item),
        };
    }
}
