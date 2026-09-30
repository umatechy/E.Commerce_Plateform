<?php

declare(strict_types=1);

namespace App\Domain\Cart\Http\Controllers;

use App\Domain\Cart\Http\Requests\AddCartItemRequest;
use App\Domain\Cart\Http\Requests\UpdateCartItemRequest;
use App\Domain\Cart\Http\Resources\CartResource;
use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Cart\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 11 §60 "Cart API". Accessible to BOTH authenticated customers
 * (auth:customer + customer.principal) and anonymous guests (Module 11
 * §6) — this controller is NOT behind the customer-only middleware
 * group; ownership is resolved per-request from either the
 * authenticated Customer or a guest cart token (§63), never from a
 * client-supplied cart/customer ID.
 *
 * No cost data, no staff-only fields, no cross-customer data ever
 * appears here — see CartResource.
 */
final class CartController
{
    private const GUEST_TOKEN_HEADER = 'X-Guest-Cart-Token';
    private const DEFAULT_CURRENCY = 'USD'; // documented placeholder — see docs/architecture/b6-cart-checkout.md "Currency"

    public function show(Request $request, CartService $carts): JsonResponse
    {
        [$cart, $newGuestToken] = $this->resolveCart($request, $carts);

        // Explicit 200: a GET that lazily creates the guest cart would
        // otherwise be answered 201 (JsonResource infers it from the
        // model's wasRecentlyCreated flag).
        $response = (new CartResource($cart))->response()->setStatusCode(200);

        if ($newGuestToken !== null) {
            $response->headers->set(self::GUEST_TOKEN_HEADER, $newGuestToken);
        }

        return $response;
    }

    public function addItem(AddCartItemRequest $request, CartService $carts): JsonResponse
    {
        [$cart, $newGuestToken] = $this->resolveCart($request, $carts);

        $carts->addItem(
            $cart,
            productId: $request->filled('product')
                ? $this->idFor(\App\Domain\Catalog\Models\Product::class, $request->string('product')->toString(), 'product')
                : $request->input('product_id'),
            productVariantId: $request->filled('variant')
                ? $this->idFor(\App\Domain\Catalog\Models\ProductVariant::class, $request->string('variant')->toString(), 'variant')
                : $request->input('product_variant_id'),
            quantity: (int) $request->input('quantity'),
        );

        $response = (new CartResource($cart->fresh()))->response()->setStatusCode(201);

        if ($newGuestToken !== null) {
            $response->headers->set(self::GUEST_TOKEN_HEADER, $newGuestToken);
        }

        return $response;
    }

    public function updateItem(UpdateCartItemRequest $request, CartItem $item, CartService $carts): CartResource
    {
        [$cart] = $this->resolveCart($request, $carts);
        $this->assertOwnsCartItem($cart, $item);

        $carts->updateItemQuantity($cart, $item, (int) $request->input('quantity'));

        return new CartResource($cart->fresh());
    }

    public function removeItem(Request $request, CartItem $item, CartService $carts): CartResource
    {
        [$cart] = $this->resolveCart($request, $carts);
        $this->assertOwnsCartItem($cart, $item);

        $carts->removeItem($cart, $item);

        return new CartResource($cart->fresh());
    }

    /** Module 14 §23-26 "Coupon Code / Coupon Validation" (Phase B9). */
    public function applyCoupon(Request $request, CartService $carts): JsonResponse
    {
        [$cart] = $this->resolveCart($request, $carts);
        $request->validate(['code' => ['required', 'string', 'max:64']]);

        try {
            $carts->applyCoupon($cart, $request->string('code')->toString());
        } catch (\App\Domain\Promotions\Exceptions\CouponNotEligibleException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'coupon_not_eligible'], 422);
        }

        return (new CartResource($cart->fresh()))->response();
    }

    /** Module 14 §27 "Coupon Removal" — cart recalculates immediately (the response's live totals() reflect it). */
    public function removeCoupon(Request $request, CartService $carts): CartResource
    {
        [$cart] = $this->resolveCart($request, $carts);
        $carts->removeCoupon($cart);

        return new CartResource($cart->fresh());
    }

    /**
     * Module 11 §62 "Customer Ownership": the cart is ALWAYS resolved
     * from the authenticated principal or a possessed guest token
     * (delegated to CartService::resolveForRequest() — shared with
     * CheckoutController, not duplicated here), never from any
     * client-supplied cart ID in a route parameter (there is none —
     * deliberately, this whole controller has no {cart} route
     * parameter at all).
     *
     * @return array{0: Cart, 1: ?string} [$cart, $newlyIssuedGuestToken]
     */
    private function resolveCart(Request $request, CartService $carts): array
    {
        return $carts->resolveForRequest($request, self::GUEST_TOKEN_HEADER, self::DEFAULT_CURRENCY);
    }

    private function assertOwnsCartItem(Cart $cart, CartItem $item): void
    {
        abort_unless($item->cart_id === $cart->id, 404);
    }

    /**
     * A public id → internal id, looked up under the tenant scope, so
     * another store's product is simply "not found".
     *
     * @param class-string<\Illuminate\Database\Eloquent\Model> $model
     */
    private function idFor(string $model, string $publicId, string $field): int
    {
        $id = $model::query()->where('public_id', $publicId)->value('id');

        if ($id === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([$field => "This {$field} does not exist in this store."]);
        }

        return (int) $id;
    }
}
