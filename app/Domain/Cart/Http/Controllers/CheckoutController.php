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
use App\Domain\Payments\Exceptions\OnlinePaymentRefusedException;
use App\Domain\Payments\Exceptions\PaymentAlreadyExistsException;
use App\Domain\Payments\Http\Resources\PaymentAttemptResource;
use App\Domain\Payments\Models\PaymentStatus;
use App\Domain\Payments\Services\OnlinePaymentService;
use App\Domain\Payments\Http\Resources\PaymentResource;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Shipping\Exceptions\DestinationNotServiceableException;
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
    /**
     * Phase B46 (Module 11 §12/§28, Module 05 §43): the order's total before
     * it is placed — discount, shipping and tax from the server, the same
     * way checkout prices it. Guest-accessible like the cart; read-only.
     */
    public function summary(\Illuminate\Http\Request $request, CartService $carts, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate([
            'shipping_method_id' => ['nullable', 'integer'],
            'shipping_address' => ['nullable', 'array'],
            'shipping_address.country' => ['nullable', 'string', 'size:2'],
            'shipping_address.province' => ['nullable', 'string', 'max:80'],
            'shipping_address.city' => ['nullable', 'string', 'max:120'],
            'shipping_address.postal_code' => ['nullable', 'string', 'max:20'],
            'billing_address' => ['nullable', 'array'],
            'billing_address.country' => ['nullable', 'string', 'size:2'],
            'billing_address.province' => ['nullable', 'string', 'max:80'],
        ]);
        [$cart] = $carts->resolveForRequest($request, 'X-Guest-Cart-Token', $carts->storeCurrency());

        try {
            return response()->json(['data' => $checkout->summary($cart, $data)]);
        } catch (CartCheckoutNotAllowedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'checkout_not_allowed'], 422);
        } catch (DestinationNotServiceableException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'destination_not_serviceable'], 422);
        }
    }

    public function store(CheckoutRequest $request, CartService $carts, CheckoutService $checkout, OnlinePaymentService $onlinePayments): JsonResponse
    {
        [$cart] = $carts->resolveForRequest($request, 'X-Guest-Cart-Token', $carts->storeCurrency());

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
                    'billing_address', 'shipping_address', 'notes', 'shipping_method_id',
                    // Only a signed-in customer has store credit; a guest's flag is ignored.
                    ...($customer !== null ? ['use_store_credit'] : []),
                ]),
                idempotencyKey: $request->string('idempotency_key')->toString(),
            );
        } catch (CartCheckoutNotAllowedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'checkout_not_allowed'], 422);
        } catch (DestinationNotServiceableException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'destination_not_serviceable'], 422);
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
        } catch (OnlinePaymentRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'payment_method_unavailable', 'reason' => $e->reason], 422);
        }

        $order = $result['order'];
        $statusCode = $order->wasRecentlyCreated ? 201 : 200;

        // Phase B48: a wallet payment starts now, after the order is safely stored. The shopper's
        // page then follows the payment with the token (a guest has no other key to it).
        $payment = $result['payment'];
        $online = null;
        if ($payment->method->walletProvider() !== null && $payment->status === PaymentStatus::RequiresAction) {
            $online = ['token' => $onlinePayments->issueClientToken($payment), 'attempt' => null, 'error' => null];
            if ($order->wasRecentlyCreated) {
                try {
                    $online['attempt'] = (new PaymentAttemptResource($onlinePayments->startForOrder($payment, $payment->method, (array) $request->input('payer', []))))->toArray($request);
                } catch (OnlinePaymentRefusedException $e) {
                    $online['error'] = ['code' => $e->reason, 'message' => $e->getMessage()];
                }
            }
            $payment->refresh();
        }

        return response()->json([
            'data' => [
                'order' => (new OrderResource($order->load('items')))->toArray($request),
                'payment' => (new PaymentResource($payment))->toArray($request),
                'redirect_url' => $result['redirect_url'],
                'online_payment' => $online,
            ],
        ], $statusCode);
    }
}
