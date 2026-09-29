<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Http\Requests\StoreCategoryRequest;
use App\Domain\Catalog\Http\Resources\CategoryResource;
use App\Domain\Catalog\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Module 07 §8 "Category Parenting Rules" — validated here (not only
 * via a DB constraint) because "must not create circular hierarchy" is
 * a graph property a single foreign-key constraint cannot express.
 */
final class CategoryController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('viewAny', Category::class);

        return CategoryResource::collection(Category::query()->orderBy('sort_order')->get());
    }

    public function store(StoreCategoryRequest $request): CategoryResource
    {
        Gate::forUser($request->user())->authorize('manage', Category::class);

        $this->assertValidParent($request->input('parent_id'));

        $category = Category::query()->create([
            ...$request->validated(),
            'slug' => Str::slug($request->string('name')->toString()).'-'.Str::lower(Str::random(6)),
        ]);

        return new CategoryResource($category);
    }

    public function update(StoreCategoryRequest $request, Category $category): CategoryResource
    {
        Gate::forUser($request->user())->authorize('manage', $category);

        $parentId = $request->input('parent_id');

        if ($parentId !== null) {
            $this->assertValidParent($parentId, $category);
        }

        $category->update($request->validated());

        return new CategoryResource($category->refresh());
    }

    public function destroy(Request $request, Category $category): \Illuminate\Http\Response
    {
        Gate::forUser($request->user())->authorize('manage', $category);

        $category->delete(); // soft delete — children's parent_id is nulled by FK (nullOnDelete), never cascaded destructively

        return response()->noContent();
    }

    /**
     * Module 07 §8: no self-parent, no cycles, same tenant only (the
     * `exists:categories,id` rule in StoreCategoryRequest already
     * proves the parent exists somewhere — but NOT that it's in this
     * tenant, since that rule has no tenant filter; BelongsToTenant's
     * global scope on Category::find() below IS the tenant check —
     * a cross-tenant parent_id 404s here exactly like everywhere else).
     */
    private function assertValidParent(?int $parentId, ?Category $self = null): void
    {
        if ($parentId === null) {
            return;
        }

        if ($self !== null && $parentId === $self->id) {
            throw ValidationException::withMessages(['parent_id' => 'A category cannot be its own parent.']);
        }

        $parent = Category::query()->find($parentId); // tenant-scoped by BelongsToTenant

        if ($parent === null) {
            throw ValidationException::withMessages(['parent_id' => 'The selected parent category does not exist in this store.']);
        }

        if ($self !== null) {
            // Walk the proposed parent's ancestor chain — if $self appears
            // in it, attaching $self under $parentId would create a cycle.
            $current = $parent;
            while ($current !== null) {
                if ($current->id === $self->id) {
                    throw ValidationException::withMessages(['parent_id' => 'This change would create a circular category hierarchy.']);
                }
                $current = $current->parent;
            }
        }
    }
}
