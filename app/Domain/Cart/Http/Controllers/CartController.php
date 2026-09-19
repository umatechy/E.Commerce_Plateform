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

        $response = (new CartResource($cart))->response();

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
            productId: $request->input('product_id'),
            productVariantId: $request->input('product_variant_id'),
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
}
