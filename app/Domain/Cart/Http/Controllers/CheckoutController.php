<?php

declare(strict_types=1);

namespace App\Domain\Cart\Http\Controllers;

use App\Domain\Cart\Exceptions\CartCheckoutNotAllowedException;
use App\Domain\Cart\Http\Requests\CheckoutRequest;
use App\Domain\Cart\Services\CartService;
use App\Domain\Cart\Services\CheckoutService;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Orders\Http\Resources\OrderResource;
use App\Domain\Orders\Models\Customer;
use App\Domain\Packages\Exceptions\FeatureNotEntitledException;
use App\Domain\Packages\Exceptions\SubscriptionInactiveException;
use App\Domain\Packages\Exceptions\UsageLimitExceededException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Module 11 §61 "Checkout API" / §12 "Checkout". Guest-accessible
 * (same as CartController) — a guest must be able to check out without
 * registering (Module 11 §6, Final Rule #3 "Guest checkout must be
 * supported"). Delegates ALL order-creation logic to CheckoutService
 * → OrderService (Phase B5, unchanged) — this controller contains no
 * pricing, inventory, or order logic of its own.
 */
final class CheckoutController
{
    public function store(CheckoutRequest $request, CartService $carts, CheckoutService $checkout): JsonResponse
    {
        [$cart] = $carts->resolveForRequest($request, 'X-Guest-Cart-Token', 'USD');

        $customer = $request->user() instanceof Customer ? $request->user() : null;

        if ($customer === null && (! $request->filled('guest_name') || ! $request->filled('guest_email'))) {
            throw ValidationException::withMessages([
                'guest_email' => 'Guest checkout requires a name and email.',
            ]);
        }

        try {
            $order = $checkout->checkout(
                $cart,
                checkoutData: $request->only([
                    'guest_name', 'guest_email', 'guest_phone',
                    'billing_address', 'shipping_address', 'notes',
                ]),
                idempotencyKey: $request->string('idempotency_key'),
            );
        } catch (CartCheckoutNotAllowedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'checkout_not_allowed'], 422);
        } catch (FeatureNotEntitledException|SubscriptionInactiveException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (UsageLimitExceededException $e) {
            return response()->json([
                'message' => "This store has reached its monthly order limit. Please try again later.",
                'code' => 'usage_limit_exceeded',
            ], 403);
        } catch (InsufficientStockException $e) {
            return response()->json([
                'message' => 'One or more items in your cart are out of stock.',
                'code' => 'insufficient_stock',
            ], 422);
        }

        return (new OrderResource($order->load('items')))->response()->setStatusCode($order->wasRecentlyCreated ? 201 : 200);
    }
}
