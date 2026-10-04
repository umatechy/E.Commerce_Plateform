<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Http\Resources\ProductResource;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductRelation;
use App\Domain\Catalog\Models\Tag;
use App\Domain\Catalog\Services\BulkProductService;
use App\Domain\Catalog\Services\ProductDuplicator;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Packages\Exceptions\FeatureNotEntitledException;
use App\Domain\Packages\Exceptions\SubscriptionInactiveException;
use App\Domain\Packages\Exceptions\UsageLimitExceededException;
use App\Domain\Packages\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Phase B39 — gap G15: the product tools of Module 06 beyond one product's
 * own form — the store's tags (§35), duplication (§60), bulk changes
 * (§46–47) and related / cross-sell / up-sell / alternative products (§38).
 */
final class ProductToolsController
{
    public const MAX_RELATED_PER_TYPE = 20;

    /** The store's tags with how many products carry each, for the tag picker and the rule builder. */
    public function tags(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', Product::class);

        $counts = DB::table('product_tag')->selectRaw('tag_id, count(*) as c')->groupBy('tag_id')->pluck('c', 'tag_id');

        return response()->json(['data' => Tag::query()->orderBy('name')->get()
            ->map(fn (Tag $t) => ['id' => $t->id, 'name' => $t->name, 'slug' => $t->slug, 'product_count' => (int) ($counts[$t->id] ?? 0)])->values()]);
    }

    /**
     * A copy is a draft, so it counts against the package's product limit like
     * any new product (ProductStatus::countsTowardUsageLimit).
     */
    public function duplicate(Request $request, Product $product, EntitlementService $entitlements, ProductDuplicator $duplicator): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $product);
        Gate::forUser($request->user())->authorize('create', Product::class);

        try {
            $entitlements->assertCanUse('products.basic', 'max_products');
        } catch (FeatureNotEntitledException|SubscriptionInactiveException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (UsageLimitExceededException $e) {
            return response()->json([
                'message' => "You've reached your plan's product limit ({$e->limit}). Upgrade your package to add more products.",
                'code' => 'usage_limit_exceeded',
                'limit' => $e->limit,
            ], 403);
        }

        $copy = $duplicator->duplicate($product, $request->user()->id);
        $entitlements->recordUsage('max_products');

        return (new ProductResource($copy->load(['brand', 'variants', 'categories'])))->response()->setStatusCode(201);
    }

    public function bulk(Request $request, BulkProductService $bulk): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', Product::class);
        $data = $request->validate([
            'action' => ['required', Rule::in(BulkProductService::ACTIONS)],
            'products' => ['required', 'array', 'min:1', 'max:'.BulkProductService::MAX_PRODUCTS],
            'products.*' => ['string', 'size:26'],
            'params' => ['sometimes', 'array'],
        ]);

        return response()->json(['data' => $bulk->run($request->user(), $data['action'], $data['products'], $data['params'] ?? [])]);
    }

    public function relations(Request $request, Product $product): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $product);

        return response()->json(['data' => $this->presentRelations($product)]);
    }

    /**
     * Replaces the product's relations: per type, the products in their order.
     * A type left out is left as it is; an empty list removes that type.
     */
    public function saveRelations(Request $request, Product $product): JsonResponse
    {
        Gate::forUser($request->user())->authorize('update', $product);
        $data = $request->validate([
            'relations' => ['required', 'array'],
            'relations.*' => ['present', 'array', 'max:'.self::MAX_RELATED_PER_TYPE],
            'relations.*.*' => ['string', 'size:26'],
        ]);
        if (array_diff(array_keys($data['relations']), ProductRelation::TYPES) !== []) {
            throw ValidationException::withMessages(['relations' => 'Unknown kind of relation.']);
        }

        $wanted = array_values(array_unique(array_merge(...array_values($data['relations']))));
        // Tenant-scoped: another store's product is simply not found.
        $ids = Product::query()->whereIn('public_id', $wanted)->pluck('id', 'public_id');
        if ($ids->count() !== count($wanted)) {
            throw ValidationException::withMessages(['relations' => 'One of the products is not in this store.']);
        }
        if ($ids->contains($product->id)) {
            throw ValidationException::withMessages(['relations' => 'A product cannot be related to itself.']);
        }

        DB::transaction(function () use ($product, $data, $ids) {
            foreach ($data['relations'] as $type => $publicIds) {
                // One by one, so the storefront cache hears of each change (model events).
                ProductRelation::query()->where('product_id', $product->id)->where('type', $type)->get()->each->delete();
                foreach (array_values(array_unique($publicIds)) as $position => $publicId) {
                    ProductRelation::query()->create(['product_id' => $product->id, 'related_product_id' => $ids[$publicId], 'type' => $type, 'position' => $position]);
                }
            }
            $product->touch();
        });
        app(AuditLogger::class)->record('product.relations_updated', ['types' => array_keys($data['relations'])], $product, null, $request->user());

        return response()->json(['data' => $this->presentRelations($product)]);
    }

    /** @return array<string, list<array{id: string, name: string, status: string}>> */
    private function presentRelations(Product $product): array
    {
        $out = array_fill_keys(ProductRelation::TYPES, []);
        $rows = ProductRelation::query()->where('product_id', $product->id)->with('related:id,public_id,name,status')->orderBy('position')->get();
        foreach ($rows as $row) {
            if ($row->related !== null) {
                $out[$row->type][] = ['id' => $row->related->public_id, 'name' => $row->related->name, 'status' => $row->related->status->value];
            }
        }

        return $out;
    }
}
