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

        return ProductResource::collection(
            Product::query()->with(['brand', 'variants'])->paginate(25)
        );
    }

    public function show(Request $request, Product $product): ProductResource
    {
        Gate::forUser($request->user())->authorize('view', $product);

        return new ProductResource($product->load(['brand', 'variants']));
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
                ...$request->validated(),
                'slug' => Str::slug($request->string('name')).'-'.Str::lower(Str::random(6)),
                'status' => $status,
            ]);

            if ($request->has('category_ids')) {
                $product->categories()->sync($request->input('category_ids', []));
            }

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
        $newStatus = $request->has('status') ? ProductStatus::from($request->string('status')) : $previousStatus;

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
            $product->update([...$request->validated(), 'status' => $newStatus]);

            if ($request->has('category_ids')) {
                $product->categories()->sync($request->input('category_ids', []));
            }
        });

        $this->syncUsageForStatusChange($entitlements, $previousStatus, $newStatus);

        return new ProductResource($product->refresh());
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
