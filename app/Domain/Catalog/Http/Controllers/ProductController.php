<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Http\Requests\StoreProductRequest;
use App\Domain\Catalog\Http\Requests\UpdateProductRequest;
use App\Domain\Catalog\Http\Resources\ProductResource;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductStatus;
use App\Domain\Packages\Exceptions\FeatureNotEntitledException;
use App\Domain\Packages\Exceptions\SubscriptionInactiveException;
use App\Domain\Packages\Exceptions\UsageLimitExceededException;
use App\Domain\Packages\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 06 §52-53 "Package Limits / Limit Enforcement" — this is
 * EntitlementService::assertCanUse()'s first real caller (built and
 * tested in Phase B2, unused until now — see
 * docs/development/b3-inspection-findings.md).
 *
 * FEATURE_KEY / USAGE_KEY are the exact entitlement keys PackageSeeder
 * (Phase B2) already seeds: 'products.basic' (feature, all 3 tiers) and
 * 'max_products' (usage limit, Basic only — Business/Premium unlimited
 * until Umar Techy configures real numbers, see B2's architecture doc).
 */
final class ProductController
{
    private const FEATURE_KEY = 'products.basic';
    private const USAGE_KEY = 'max_products';

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('viewAny', Product::class);

        // Phase B31 (G6): the admin product list searches and filters on the server.
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(ProductStatus::class)],
        ]);

        return ProductResource::collection(
            Product::query()->with(['brand', 'variants'])
                ->when($filters['search'] ?? null, function ($query, string $search) {
                    $like = '%'.addcslashes($search, '%_\\').'%';
                    $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('sku', 'like', $like));
                })
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->orderByDesc('id')
                ->paginate(25)
        );
    }

    public function show(Request $request, Product $product): ProductResource
    {
        Gate::forUser($request->user())->authorize('view', $product);

        return new ProductResource($product->load(['brand', 'variants', 'categories', 'tags', 'collections']));
    }

    public function store(StoreProductRequest $request, EntitlementService $entitlements): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', Product::class);

        $this->assertRelationsBelongToTenant($request);

        $status = ProductStatus::from($request->input('status', 'draft'));

        try {
            if ($status->countsTowardUsageLimit()) {
                $entitlements->assertCanUse(self::FEATURE_KEY, self::USAGE_KEY);
            } else {
                $entitlements->assertFeatureEntitled(self::FEATURE_KEY);
            }
        } catch (FeatureNotEntitledException|SubscriptionInactiveException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (UsageLimitExceededException $e) {
            // Module 06 §53: "clear upgrade messaging when a limit is reached."
            return response()->json([
                'message' => "You've reached your plan's product limit ({$e->limit}). Upgrade your package to add more products.",
                'code' => 'usage_limit_exceeded',
                'limit' => $e->limit,
            ], 403);
        }

        $product = DB::transaction(function () use ($request, $status) {
            $product = Product::query()->create([
                ...$request->safe()->except(['tags', 'collection_ids']),
                'slug' => Str::slug($request->string('name')->toString()).'-'.Str::lower(Str::random(6)),
                'status' => $status,
            ]);

            if ($request->has('category_ids')) {
                $product->categories()->sync($request->input('category_ids', []));
            }
            $this->syncMerchandising($request, $product);

            return $product;
        });

        if ($status->countsTowardUsageLimit()) {
            $entitlements->recordUsage(self::USAGE_KEY);
        }

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function update(UpdateProductRequest $request, Product $product, EntitlementService $entitlements): ProductResource
    {
        Gate::forUser($request->user())->authorize('update', $product);

        $this->assertRelationsBelongToTenant($request);

        $previousStatus = $product->status;
        $newStatus = $request->has('status') ? ProductStatus::from($request->string('status')->toString()) : $previousStatus;

        // A status change that would START counting again (e.g.
        // unarchiving) must pass the same limit check a brand-new
        // product would — never let a status transition become a
        // silent backdoor around Module 06 §53's enforcement.
        if (! $previousStatus->countsTowardUsageLimit() && $newStatus->countsTowardUsageLimit()) {
            try {
                $entitlements->assertWithinLimit(self::USAGE_KEY);
            } catch (UsageLimitExceededException $e) {
                abort(403, "You've reached your plan's product limit ({$e->limit}). Upgrade your package to restore this product.");
            }
        }

        DB::transaction(function () use ($request, $product, $newStatus) {
            $product->update([...$request->safe()->except(['tags', 'collection_ids']), 'status' => $newStatus]);

            if ($request->has('category_ids')) {
                $product->categories()->sync($request->input('category_ids', []));
            }
            $this->syncMerchandising($request, $product);
        });

        $this->syncUsageForStatusChange($entitlements, $previousStatus, $newStatus);

        return new ProductResource($product->refresh()->load(['brand', 'variants', 'categories', 'tags', 'collections']));
    }

    public function destroy(Request $request, Product $product, EntitlementService $entitlements): \Illuminate\Http\Response
    {
        Gate::forUser($request->user())->authorize('delete', $product);

        $wasCountingBeforeDelete = $product->status->countsTowardUsageLimit();

        $product->delete(); // soft delete (Module 06 §82) — historical order integrity (§32) preserved

        if ($wasCountingBeforeDelete) {
            $entitlements->releaseUsage(self::USAGE_KEY);
        }

        return response()->noContent();
    }

    /**
     * Phase B39: the product's tags (by name) and the hand-picked collections
     * it is in. Collections need collections.manage; only manual collections
     * of this store can be chosen, and a product joins at the end of each.
     */
    private function syncMerchandising(Request $request, Product $product): void
    {
        if ($request->has('tags')) {
            app(\App\Domain\Catalog\Services\ProductTagService::class)->sync($product, array_values($request->input('tags', [])));
        }
        if (! $request->has('collection_ids')) {
            return;
        }
        Gate::forUser($request->user())->authorize('manage', \App\Domain\Catalog\Models\Collection::class);
        $wanted = array_values(array_unique($request->input('collection_ids', [])));
        $ids = \App\Domain\Catalog\Models\Collection::query()->where('type', 'manual')->whereIn('public_id', $wanted)->pluck('id');
        if ($ids->count() !== count($wanted)) {
            throw ValidationException::withMessages(['collection_ids' => 'Choose hand-picked collections of this store.']);
        }
        $current = $product->collections()->pluck('collections.id');
        $product->collections()->detach($current->diff($ids)->all());
        foreach ($ids->diff($current) as $collectionId) {
            $product->collections()->attach($collectionId, ['position' => (int) DB::table('collection_product')->where('collection_id', $collectionId)->max('position') + 1]);
        }
        \App\Domain\Catalog\Models\Collection::query()->whereIn('id', $current->merge($ids)->unique())->get()->each->touch();
    }

    private function syncUsageForStatusChange(
        EntitlementService $entitlements,
        ProductStatus $previous,
        ProductStatus $new,
    ): void {
        $wasCounting = $previous->countsTowardUsageLimit();
        $nowCounting = $new->countsTowardUsageLimit();

        if ($wasCounting && ! $nowCounting) {
            $entitlements->releaseUsage(self::USAGE_KEY); // e.g. archived — frees quota
        } elseif (! $wasCounting && $nowCounting) {
            $entitlements->recordUsage(self::USAGE_KEY); // e.g. unarchived — consumes quota again
        }
    }

    /**
     * Module 06 §3 "Tenant Isolation": a Store A request must never be
     * able to associate its product with Store B's brand/category.
     * `exists:brands,id` / `exists:categories,id` in the FormRequest
     * only prove the row exists SOMEWHERE — not in this tenant — so
     * this explicit, tenant-scoped re-check (via BelongsToTenant's
     * global scope on Brand::find()/Category::find()) is required.
     * Mirrors CategoryController::assertValidParent()'s same pattern.
     *
     * @throws ValidationException
     */
    private function assertRelationsBelongToTenant(Request $request): void
    {
        if ($request->filled('brand_id') && Brand::query()->find($request->input('brand_id')) === null) {
            throw ValidationException::withMessages(['brand_id' => 'The selected brand does not exist in this store.']);
        }

        if ($request->filled('primary_category_id') && Category::query()->find($request->input('primary_category_id')) === null) {
            throw ValidationException::withMessages(['primary_category_id' => 'The selected category does not exist in this store.']);
        }

        foreach ($request->input('category_ids', []) as $categoryId) {
            if (Category::query()->find($categoryId) === null) {
                throw ValidationException::withMessages(['category_ids' => 'One or more selected categories do not exist in this store.']);
            }
        }
    }
}
