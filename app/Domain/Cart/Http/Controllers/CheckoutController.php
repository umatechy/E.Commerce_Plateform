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
use App\Domain\Payments\Exceptions\PaymentAlreadyExistsException;
use App\Domain\Payments\Http\Resources\PaymentResource;
use App\Domain\Payments\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Module 11 §61 "Checkout API" / Module 12 §69 "Payment API" (the
 * `POST /payments` concept is folded into checkout itself here, per
 * the Architectural Decision in b7-inspection-findings.md — a payment
 * is always created as part of checkout, never as an independent
 * standalone call in B7's scope). Guest-accessible (same as
 * CartController). Delegates ALL logic to CheckoutService →
 * OrderService/PaymentService (Phase B5/B7, unchanged) — this
 * controller contains no pricing, inventory, or payment logic itself.
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
            $result = $checkout->checkout(
                $cart,
                paymentMethod: PaymentMethod::from($request->string('payment_method')->toString()),
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
                'message' => 'This store has reached its monthly order limit. Please try again later.',
                'code' => 'usage_limit_exceeded',
            ], 403);
        } catch (InsufficientStockException $e) {
            return response()->json([
                'message' => 'One or more items in your cart are out of stock.',
                'code' => 'insufficient_stock',
            ], 422);
        } catch (PaymentAlreadyExistsException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'payment_already_exists'], 409);
        }

        $order = $result['order'];
        $statusCode = $order->wasRecentlyCreated ? 201 : 200;

        return response()->json([
            'data' => [
                'order' => (new OrderResource($order->load('items')))->toArray($request),
                'payment' => (new PaymentResource($result['payment']))->toArray($request),
                'redirect_url' => $result['redirect_url'],
            ],
        ], $statusCode);
    }
}
