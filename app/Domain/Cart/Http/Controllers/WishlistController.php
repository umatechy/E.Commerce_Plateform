<?php

declare(strict_types=1);

namespace App\Domain\Cart\Http\Controllers;

use App\Domain\Cart\Http\Requests\AddWishlistItemRequest;
use App\Domain\Cart\Http\Resources\CartResource;
use App\Domain\Cart\Http\Resources\WishlistItemResource;
use App\Domain\Cart\Models\WishlistItem;
use App\Domain\Cart\Services\CartService;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Orders\Models\Customer;
use App\Domain\Packages\Exceptions\FeatureNotEntitledException;
use App\Domain\Packages\Exceptions\SubscriptionInactiveException;
use App\Domain\Packages\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Module 11 §64-71 "Wishlist". Authenticated-customer-only in B6 —
 * this controller sits behind auth:customer + customer.principal
 * (guest wishlist is explicitly client-side/local-storage per §69, see
 * docs/development/b6-inspection-findings.md). Ownership is always the
 * AUTHENTICATED customer's own ID — no {customer} route parameter
 * exists anywhere in this controller.
 */
final class WishlistController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var Customer $customer */
        $customer = $request->user();

        return WishlistItemResource::collection(
            $customer->wishlistItems()->with(['product.images', 'variant.product.images'])->get()
        );
    }

    /**
     * Module 11 §66: "duplicate-safe insertion" — firstOrCreate against
     * the table's own unique constraint, not merely a pre-check (closes
     * the same class of race a pre-check-then-insert would leave open).
     */
    public function store(AddWishlistItemRequest $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        try {
            app(EntitlementService::class)->assertFeatureEntitled('wishlist.basic');
        } catch (FeatureNotEntitledException|SubscriptionInactiveException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        // Tenant-scoped existence checks (mirrors Phase B3/B5's identical
        // cross-tenant relation-validation pattern); public ids (Phase
        // B25) resolve the same way.
        $variant = match (true) {
            $request->filled('variant') => ProductVariant::query()->where('public_id', $request->string('variant')->toString())->first(),
            $request->filled('product_variant_id') => ProductVariant::query()->find($request->input('product_variant_id')),
            default => null,
        };
        if (($request->filled('variant') || $request->filled('product_variant_id')) && $variant === null) {
            throw ValidationException::withMessages(['product_variant_id' => 'This variant does not exist in this store.']);
        }

        $product = $variant !== null ? $variant->product : ($request->filled('product')
            ? Product::query()->where('public_id', $request->string('product')->toString())->first()
            : Product::query()->find($request->input('product_id')));
        if ($product === null) {
            throw ValidationException::withMessages(['product_id' => 'This product does not exist in this store.']);
        }

        // Phase B25 fix: a variant item used to be saved with product_id
        // NULL, so the wishlist showed it without a name and as
        // "unavailable". The product is now always recorded.
        $item = WishlistItem::query()->firstOrCreate([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
        ]);

        return (new WishlistItemResource($item->load(['product.images', 'variant.product.images'])))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, WishlistItem $item): \Illuminate\Http\Response
    {
        /** @var Customer $customer */
        $customer = $request->user();
        abort_unless($item->customer_id === $customer->id, 404);

        $item->delete();

        return response()->noContent();
    }

    /**
     * Module 11 §68 "Wishlist to Cart": "must perform full product,
     * variant, price, inventory, and entitlement validation" — reuses
     * CartService::addItem() UNCHANGED, which already does exactly
     * this (no duplicate validation logic).
     */
    public function moveToCart(Request $request, WishlistItem $item, CartService $carts): CartResource
    {
        /** @var Customer $customer */
        $customer = $request->user();
        abort_unless($item->customer_id === $customer->id, 404);

        [$cart] = $carts->resolveForRequest($request, 'X-Guest-Cart-Token', $carts->storeCurrency());

        $carts->addItem($cart, $item->product_id, $item->product_variant_id, quantity: 1);
        $item->delete();

        return new CartResource($cart->fresh());
    }
}
