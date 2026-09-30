<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\ProductImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Phase B24 — staff management of a product's storefront images. Both
 * {product} and {image} bind under the tenant scope (another store's →
 * 404), and the image must belong to the product in the URL.
 */
final class ProductImageController
{
    public function index(Request $request, Product $product): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $product);

        return response()->json(['data' => $product->images()->get()->map(fn (ProductImage $image) => $this->present($image))]);
    }

    public function store(Request $request, Product $product, ProductImageService $images): JsonResponse
    {
        Gate::forUser($request->user())->authorize('update', $product);

        $validated = $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.(int) config('storefront.images.max_kilobytes')],
            'alt' => ['nullable', 'string', 'max:255'],
            'variant_id' => ['nullable', 'string', Rule::exists('product_variants', 'public_id')->where('product_id', $product->id)->whereNull('deleted_at')],
        ]);

        $variantId = isset($validated['variant_id'])
            ? ProductVariant::query()->where('public_id', $validated['variant_id'])->value('id')
            : null;

        $image = $images->add($product, $validated['image'], $validated['alt'] ?? null, $variantId);

        return response()->json(['data' => $this->present($image)], 201);
    }

    public function reorder(Request $request, Product $product, ProductImageService $images): JsonResponse
    {
        Gate::forUser($request->user())->authorize('update', $product);
        $validated = $request->validate(['images' => ['required', 'array', 'max:50'], 'images.*' => ['string', 'distinct']]);

        $images->reorder($product, array_values($validated['images']));

        return $this->index($request, $product);
    }

    public function destroy(Request $request, Product $product, ProductImage $image, ProductImageService $images): JsonResponse
    {
        Gate::forUser($request->user())->authorize('update', $product);
        abort_unless($image->product_id === $product->id, 404);

        $images->remove($image);

        return response()->json(status: 204);
    }

    /** @return array<string, mixed> */
    private function present(ProductImage $image): array
    {
        return [
            'id' => $image->public_id,
            'url' => $image->url(),
            'alt' => $image->alt,
            'position' => $image->position,
            'width' => $image->width,
            'height' => $image->height,
            'variant_id' => $image->variant?->public_id,
        ];
    }
}
