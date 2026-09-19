<?php

declare(strict_types=1);

namespace App\Domain\Cart\Services;

use App\Domain\Cart\Exceptions\CartCheckoutNotAllowedException;
use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartStatus;
use App\Domain\Catalog\Models\ProductType;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Packages\Services\EntitlementService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Shipping\Exceptions\DestinationNotServiceableException;
use App\Domain\Shipping\Services\ShippingRateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Module 11 §12/§42-58 "Checkout" + Module 12 §7 "Payment Initiation" +
 * Module 13 §18/§38 "Shipping Cost / Rate Engine". A thin orchestration
 * layer — this class contains NO order-creation, pricing, inventory,
 * payment-gateway, or shipping-rate-calculation logic of its own. Its
 * entire job is:
 *   1. Final cart-level validation (via CartService::totals()).
 *   2. If the cart is not digital-only, compute a server-authoritative
 *      shipping quote (via ShippingRateService::quote() — Phase B8,
 *      UNCHANGED here) and fold its cost into the Order total.
 *   3. Translate cart items into OrderService::createOrder()'s existing
 *      input shape.
 *   4. Call OrderService::createOrder() (Phase B5, extended additively
 *      in B8 only to accept `shipping_total_minor` — see that class).
 *   5. Call PaymentService::createForOrder() UNCHANGED (Phase B7).
 *   6. Mark the cart Converted, in the SAME outer transaction as order
 *      + payment creation (Step 13 "Transactional Consistency").
 *
 * Shipment creation is Deliberately NOT part of Checkout — see
 * docs/development/b8-inspection-findings.md "Shipment Creation
 * Timing": a Shipment is a later, staff-initiated fulfillment action.
 *
 * No persisted CheckoutSession entity exists — see
 * docs/development/b6-inspection-findings.md "Scope Decision".
 */
final class CheckoutService
{
    public function __construct(
        private readonly CartService $carts,
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
        private readonly EntitlementService $entitlements,
        private readonly ShippingRateService $shippingRates,
    ) {}

    /** Module 12 §88 "Package Entitlements" — every tier is entitled to all 3 B7 methods by default (see docs/development/b7-inspection-findings.md); the check exists so a future tier restriction is configurable without a code change. */
    private const FEATURE_KEY_BY_METHOD = [
        'cod' => 'payment.cod',
        'bank_transfer' => 'payment.bank_transfer',
        'mock_redirect' => 'payment.online',
    ];

    /**
     * @return array{order: Order, payment: Payment, redirect_url: ?string}
     * @throws CartCheckoutNotAllowedException
     * @throws ValidationException
     * @throws DestinationNotServiceableException
     * @throws \App\Domain\Packages\Exceptions\FeatureNotEntitledException
     * @throws \App\Domain\Packages\Exceptions\SubscriptionInactiveException
     * @throws \App\Domain\Packages\Exceptions\UsageLimitExceededException
     * @throws \App\Domain\Inventory\Exceptions\InsufficientStockException
     * @throws \App\Domain\Payments\Exceptions\PaymentAlreadyExistsException
     */
    public function checkout(Cart $cart, PaymentMethod $paymentMethod, array $checkoutData, string $idempotencyKey): array
    {
        if (! $cart->status->isActionable()) {
            throw new CartCheckoutNotAllowedException('This cart is no longer active and cannot be checked out.');
        }

        $this->entitlements->assertFeatureEntitled('shipping.basic');
        $this->entitlements->assertFeatureEntitled(self::FEATURE_KEY_BY_METHOD[$paymentMethod->value]);

        $totals = $this->carts->totals($cart);

        if ($totals['items'] === []) {
            throw new CartCheckoutNotAllowedException('Your cart is empty.');
        }

        if ($totals['has_issues']) {
            throw new CartCheckoutNotAllowedException('One or more items in your cart need attention before checkout can continue.');
        }

        $cartItems = $cart->items->load(['product', 'variant']);

        // Module 13 Final Rule #8: "Digital-only carts must not require
        // physical shipping." Reuses Phase B3's existing ProductType —
        // no new "requires_shipping" column was added.
        $isDigitalOnly = $cartItems->every(
            fn ($item) => $item->product !== null && in_array($item->product->type, [ProductType::Digital, ProductType::Service], true)
        );

        $shippingTotalMinor = 0;

        if (! $isDigitalOnly) {
            if (empty($checkoutData['shipping_method_id'])) {
                throw ValidationException::withMessages(['shipping_method_id' => 'A shipping method is required for this order.']);
            }
            if (empty($checkoutData['shipping_address']['country'] ?? null)) {
                throw ValidationException::withMessages(['shipping_address' => 'A shipping destination (at least a country) is required.']);
            }

            $weightedItems = $cartItems->map(fn ($item) => [
                'weight' => (float) ($item->variant?->weight ?? 0) * $item->quantity,
            ])->all();

            // Module 13 §39 "Server-Authoritative Rate" — the client
            // may REQUEST a method (shipping_method_id), never a price;
            // this call is the sole source of the actual cost.
            $quote = $this->shippingRates->quote(
                (int) $checkoutData['shipping_method_id'],
                $checkoutData['shipping_address'],
                $totals['subtotal_minor'],
                $weightedItems,
                $totals['currency'] ?? 'USD',
            );

            $shippingTotalMinor = $quote['cost_minor'];
        }

        $items = $cart->items->map(fn ($item) => [
            'product_id' => $item->product_variant_id === null ? $item->product_id : null,
            'product_variant_id' => $item->product_variant_id,
            'quantity' => $item->quantity,
        ])->all();

        return DB::transaction(function () use ($cart, $items, $checkoutData, $idempotencyKey, $paymentMethod, $shippingTotalMinor) {
            $order = $this->orders->createOrder(
                items: $items,
                orderData: [
                    'customer_id' => $cart->customer_id,
                    'guest_name' => $checkoutData['guest_name'] ?? null,
                    'guest_email' => $checkoutData['guest_email'] ?? null,
                    'guest_phone' => $checkoutData['guest_phone'] ?? null,
                    'billing_address' => $checkoutData['billing_address'] ?? $cart->billing_address_snapshot,
                    'shipping_address' => $checkoutData['shipping_address'] ?? $cart->shipping_address_snapshot,
                    'notes' => $checkoutData['notes'] ?? null,
                    'source' => 'storefront',
                    'shipping_total_minor' => $shippingTotalMinor,
                ],
                idempotencyKey: $idempotencyKey,
            );

            // Payment idempotency key is derived from the order-level
            // key (never the client's raw value reused verbatim as a
            // second, unrelated idempotency scope) — same
            // "{key}:sub-scope" convention already used for per-line-
            // item reservation keys in OrderService (Phase B5).
            $payment = $this->payments->createForOrder($order, $paymentMethod, "{$idempotencyKey}:payment");

            if ($cart->status === CartStatus::Active) {
                $cart->update([
                    'status' => CartStatus::Converted,
                    'converted_at' => now(),
                    'converted_order_id' => $order->id,
                ]);
            }

            return [
                'order' => $order,
                'payment' => $payment,
                'redirect_url' => $this->payments->redirectUrlFor($payment),
            ];
        });
    }
}
