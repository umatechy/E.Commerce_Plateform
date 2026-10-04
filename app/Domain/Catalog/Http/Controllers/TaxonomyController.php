<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Http\Resources\AttributeResource;
use App\Domain\Catalog\Models\Attribute;
use App\Domain\Catalog\Models\AttributeSet;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\CategoryAttribute;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\AttributeManager;
use App\Domain\Catalog\Services\CategoryAttributes;
use App\Domain\Catalog\Services\ProductSpecifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Phase B41 — Module 07 §18–19, §27–45: editing attributes, attribute
 * sets, the attributes of a category, and a product's specifications.
 * Attributes, sets and category lists need attributes.manage (or
 * categories.manage for a category's list); specifications need the
 * product's update permission.
 */
final class TaxonomyController
{
    public function updateAttribute(Request $request, Attribute $attribute, AttributeManager $manager): AttributeResource
    {
        Gate::forUser($request->user())->authorize('manage', $attribute);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(['select', 'multi_select', 'boolean', 'numeric', 'text', 'color'])],
            'group' => ['sometimes', 'nullable', 'string', 'max:80'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'values' => ['sometimes', 'array', 'max:'.AttributeManager::MAX_VALUES],
            'values.*.id' => ['sometimes', 'nullable', 'integer'],
            'values.*.value' => ['required_with:values', 'string', 'max:255'],
            'values.*.color_code' => ['sometimes', 'nullable', 'string', 'max:7'],
            'values.*.is_active' => ['sometimes', 'boolean'],
        ]);

        return new AttributeResource($manager->update($attribute, $data, $request->user()));
    }

    public function sets(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', Attribute::class);

        return response()->json(['data' => AttributeSet::query()->with('attributes')->orderBy('name')->get()->map(fn (AttributeSet $s) => $this->presentSet($s))->values()]);
    }

    public function saveSet(Request $request, AttributeManager $manager, ?AttributeSet $set = null): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', Attribute::class);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'attributes' => ['present', 'array', 'max:60'],
            'attributes.*' => ['integer'],
        ]);
        if (AttributeSet::query()->where('name', $data['name'])->when($set, fn ($q) => $q->whereKeyNot($set->id))->exists()) {
            return response()->json(['message' => 'A set with this name already exists.', 'errors' => ['name' => ['A set with this name already exists.']]], 422);
        }
        $saved = $manager->saveSet($set, $data['name'], $data['attributes'], $request->user());

        return response()->json(['data' => $this->presentSet($saved)], $set === null ? 201 : 200);
    }

    public function updateSet(Request $request, AttributeSet $set, AttributeManager $manager): JsonResponse
    {
        return $this->saveSet($request, $manager, $set);
    }

    public function destroySet(Request $request, AttributeSet $set): \Illuminate\Http\Response
    {
        Gate::forUser($request->user())->authorize('manage', Attribute::class);
        $set->delete();

        return response()->noContent();
    }

    public function categoryAttributes(Request $request, Category $category, CategoryAttributes $service): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', Attribute::class);

        return response()->json(['data' => $this->presentCategory($category, $service)]);
    }

    public function saveCategoryAttributes(Request $request, Category $category, CategoryAttributes $service): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', $category);
        $data = $request->validate([
            'attributes' => ['present', 'array', 'max:'.CategoryAttributes::MAX_PER_CATEGORY],
            'attributes.*.attribute_id' => ['required', 'integer'],
            'attributes.*.is_required' => ['sometimes', 'boolean'],
            'attributes.*.is_filter' => ['sometimes', 'boolean'],
            'apply_set' => ['sometimes', 'nullable', 'integer'],
        ]);
        $service->set($category, $data['attributes'], $request->user());
        if (! empty($data['apply_set'])) {
            $set = AttributeSet::query()->with('attributes')->findOrFail($data['apply_set']);
            $service->applySet($category, $set, $request->user());
        }

        return response()->json(['data' => $this->presentCategory($category, $service)]);
    }

    public function specifications(Request $request, Product $product, ProductSpecifications $specs, CategoryAttributes $categories): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $product);

        return response()->json(['data' => $this->presentSpecifications($product, $specs, $categories)]);
    }

    public function saveSpecifications(Request $request, Product $product, ProductSpecifications $specs, CategoryAttributes $categories): JsonResponse
    {
        Gate::forUser($request->user())->authorize('update', $product);
        $data = $request->validate([
            'specifications' => ['present', 'array', 'max:'.ProductSpecifications::MAX_ATTRIBUTES],
            'specifications.*.attribute_id' => ['required', 'integer'],
            'specifications.*.value' => ['present'],
        ]);
        $specs->save($product, $data['specifications'], $request->user());

        return response()->json(['data' => $this->presentSpecifications($product, $specs, $categories)]);
    }

    /** @return array<string, mixed> */
    private function presentSpecifications(Product $product, ProductSpecifications $specs, CategoryAttributes $categories): array
    {
        $category = $product->primary_category_id !== null ? Category::query()->find($product->primary_category_id) : null;

        return [
            'values' => $specs->values($product),
            // The attributes the main category suggests, in its order; required ones are marked.
            'suggested' => $category === null ? [] : $categories->effective($category)
                ->map(fn (CategoryAttribute $ca) => ['attribute_id' => $ca->attribute_id, 'is_required' => $ca->is_required])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentCategory(Category $category, CategoryAttributes $service): array
    {
        $own = $service->own($category);
        $effective = $own->isEmpty() ? $service->effective($category) : $own;

        return [
            'attributes' => $own->map(fn (CategoryAttribute $ca) => ['attribute_id' => $ca->attribute_id, 'is_required' => $ca->is_required, 'is_filter' => $ca->is_filter])->values()->all(),
            // Without its own list a category follows its nearest parent's.
            'inherited' => $own->isEmpty() && $effective->isNotEmpty()
                ? $effective->map(fn (CategoryAttribute $ca) => ['attribute_id' => $ca->attribute_id, 'is_required' => $ca->is_required, 'is_filter' => $ca->is_filter])->values()->all()
                : [],
        ];
    }

    /** @return array<string, mixed> */
    private function presentSet(AttributeSet $set): array
    {
        return ['id' => $set->id, 'name' => $set->name, 'attributes' => $set->attributes->pluck('id')->values()->all()];
    }
}
