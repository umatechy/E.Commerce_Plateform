<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Models\Attribute;
use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Settings\Services\Locales;
use App\Domain\Settings\Services\TranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Phase B42 — Module 07 §38 "Attribute localization": an attribute's name
 * and all its values in the store's other storefront languages, read and
 * saved together (one request instead of one per value). The internal key
 * and value slugs never change, so filter addresses stay the same in every
 * language. Values are reached only through their tenant-scoped attribute.
 */
final class AttributeTranslationController
{
    public function show(Request $request, Attribute $attribute, TranslationService $translations, ConfigService $config): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', Attribute::class);

        return response()->json(['data' => $this->present($attribute, $translations, $config)]);
    }

    public function update(Request $request, Attribute $attribute, TranslationService $translations, ConfigService $config, AuditLogger $audit): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', $attribute);
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys(Locales::SUPPORTED))],
            'name' => ['present', 'nullable', 'string', 'max:255'],
            'values' => ['sometimes', 'array', 'max:200'],
            'values.*' => ['nullable', 'string', 'max:255'],
        ]);
        $own = $attribute->values()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $given = array_map('intval', array_keys($data['values'] ?? []));
        if (array_diff($given, $own) !== []) {
            throw ValidationException::withMessages(['values' => 'One of the values is not a value of this attribute.']);
        }

        DB::transaction(function () use ($attribute, $data, $translations, $request) {
            $translations->save('attribute', $attribute->id, $data['locale'], ['name' => $data['name']], $request->user()->id);
            foreach ($data['values'] ?? [] as $valueId => $text) {
                $translations->save('attribute_value', (int) $valueId, $data['locale'], ['value' => $text], $request->user()->id);
            }
        });
        $attribute->touch(); // the storefront cache follows the attribute
        $audit->record('translation.updated', ['type' => 'attribute', 'locale' => $data['locale'], 'values' => count($given)], $attribute);

        return response()->json(['data' => $this->present($attribute, $translations, $config)]);
    }

    /** @return array<string, mixed> */
    private function present(Attribute $attribute, TranslationService $translations, ConfigService $config): array
    {
        $default = (string) ($config->get('store.default_locale') ?? Locales::DEFAULT);
        $values = $attribute->values()->get();
        $out = [];
        foreach ($translations->forItem('attribute', $attribute->id) as $locale => $fields) {
            $out[$locale]['name'] = $fields['name'] ?? '';
        }
        foreach ($values as $value) {
            foreach ($translations->forItem('attribute_value', $value->id) as $locale => $fields) {
                $out[$locale]['values'][$value->id] = $fields['value'] ?? '';
            }
        }

        return [
            'default_locale' => $default,
            'languages' => Locales::describe(array_values(array_diff((array) $config->get('store.languages'), [$default]))),
            'original' => ['name' => $attribute->name, 'values' => $values->map(fn (AttributeValue $v) => ['id' => $v->id, 'value' => $v->value, 'is_active' => $v->is_active])->values()->all()],
            'translations' => (object) $out,
        ];
    }
}
