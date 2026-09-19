<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Http\Requests\StoreProductVariantRequest;
use App\Domain\Catalog\Http\Resources\ProductVariantResource;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * A Product Variant is authorized through its PARENT Product's Policy
 * (Module 06 §9: "an independently sellable configuration of a parent
 * product" — access to manage variants IS access to manage the
 * product). No separate ProductVariantPolicy exists, deliberately, to
 * avoid two different authorization answers for the same underlying
 * question. Route-model binding on {product} already 404s a
 * cross-tenant product before this controller runs (ADR-001).
 */
final class ProductVariantController
{
    public function index(StoreProductVariantRequest $request, Product $product): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('view', $product);

        return ProductVariantResource::collection($product->variants);
    }

    public function store(StoreProductVariantRequest $request, Product $product): ProductVariantResource
    {
        Gate::forUser($request->user())->authorize('update', $product);

        $variant = $product->variants()->create($request->validated());

        return new ProductVariantResource($variant->load('product'));
    }

    public function update(StoreProductVariantRequest $request, Product $product, ProductVariant $variant): ProductVariantResource
    {
        Gate::forUser($request->user())->authorize('update', $product);
        abort_unless($variant->product_id === $product->id, 404);

        $variant->update($request->validated());

        return new ProductVariantResource($variant->refresh()->load('product'));
    }

    public function destroy(StoreProductVariantRequest $request, Product $product, ProductVariant $variant): \Illuminate\Http\Response
    {
        Gate::forUser($request->user())->authorize('delete', $product);
        abort_unless($variant->product_id === $product->id, 404);

        $variant->delete();

        return response()->noContent();
    }
}
