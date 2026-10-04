<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Models\Collection;
use App\Domain\Catalog\Services\CollectionService;
use App\Domain\Catalog\Services\CollectionRules;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Storefront\Services\StorefrontCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Phase B39 — Module 06 §34, Module 05 §16: the store's collections.
 * Reading needs products.view (or collections.manage); every change needs
 * collections.manage. Ids in rules and product lists are re-checked in this
 * store by CollectionRules / CollectionService.
 */
final class CollectionController
{
    public function __construct(private readonly CollectionService $collections) {}

    public function index(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', Collection::class);

        $counts = \Illuminate\Support\Facades\DB::table('collection_product')->selectRaw('collection_id, count(*) as c')->groupBy('collection_id')->pluck('c', 'collection_id');

        return response()->json(['data' => Collection::query()->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (Collection $c) => $this->present($c, (int) ($counts[$c->id] ?? 0)))->values()]);
    }

    public function show(Request $request, Collection $collection): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', Collection::class);

        $products = $collection->products()->get(['products.id', 'products.public_id', 'products.name', 'products.status', 'products.price_minor'])
            ->map(fn ($p) => ['id' => $p->public_id, 'name' => $p->name, 'status' => $p->status->value, 'price_minor' => $p->price_minor])->values();

        return response()->json(['data' => [...$this->present($collection, $products->count()), 'products' => $products, 'preview_count' => $this->previewCount($collection)]]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', Collection::class);

        $collection = $this->collections->save(null, $this->validated($request, creating: true), $request->user()->id);

        return response()->json(['data' => $this->present($collection, 0)], 201);
    }

    public function update(Request $request, Collection $collection): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', $collection);

        $collection = $this->collections->save($collection, $this->validated($request, creating: false), $request->user()->id);

        return response()->json(['data' => $this->present($collection, $collection->products()->count())]);
    }

    public function products(Request $request, Collection $collection): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', $collection);
        $data = $request->validate(['products' => ['present', 'array', 'max:500'], 'products.*' => ['string', 'max:26']]);

        $this->collections->setProducts($collection, $data['products']);
        app(AuditLogger::class)->record('collection.products_set', ['count' => count($data['products'])], $collection, null, $request->user());

        return response()->json(['data' => ['count' => $collection->products()->count()]]);
    }

    public function destroy(Request $request, Collection $collection): \Illuminate\Http\Response
    {
        Gate::forUser($request->user())->authorize('manage', $collection);

        $collection->delete(); // soft delete; storefront links to it simply stop working
        app(AuditLogger::class)->record('collection.deleted', ['name' => $collection->name], $collection, null, $request->user());

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $sometimes = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$sometimes, 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'type' => [$creating ? 'required' : 'prohibited', Rule::in(Collection::TYPES)], // a collection keeps its kind
            'rules' => ['sometimes', 'nullable', 'array', 'max:'.CollectionRules::MAX_CONDITIONS],
            'match' => ['sometimes', Rule::in(['all', 'any'])],
            'sort' => ['sometimes', Rule::in(Collection::SORTS)],
            'status' => ['sometimes', Rule::in(['draft', 'active'])],
            'is_visible' => ['sometimes', 'boolean'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ]);
    }

    private function previewCount(Collection $collection): int
    {
        return app(StorefrontCatalog::class)->collectionCount($collection);
    }

    /** @return array<string, mixed> */
    private function present(Collection $c, int $productCount): array
    {
        return [
            // internal_id: the key promotion targets take (staff-only API, like ProductResource).
            'id' => $c->public_id, 'internal_id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'description' => $c->description,
            'type' => $c->type, 'rules' => $c->rules ?? [], 'match' => $c->match, 'sort' => $c->sort,
            'status' => $c->status, 'is_visible' => $c->is_visible, 'is_live' => $c->isLive(),
            'starts_at' => $c->starts_at?->toIso8601String(), 'ends_at' => $c->ends_at?->toIso8601String(),
            'sort_order' => $c->sort_order, 'product_count' => $productCount,
        ];
    }
}
