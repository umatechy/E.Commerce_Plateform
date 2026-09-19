<?php

declare(strict_types=1);

namespace App\Domain\Cart\Services;

use App\Domain\Cart\Exceptions\CartCheckoutNotAllowedException;
use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Packages\Services\EntitlementService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Services\PaymentService;
use Illuminate\Support\Facades\DB;

/**
 * Module 11 §12/§42-58 "Checkout" + Module 12 §7 "Payment Initiation".
 * A thin orchestration layer — this class contains NO order-creation,
 * pricing, inventory, or payment-gateway logic of its own. Its entire
 * job is:
 *   1. Final cart-level validation (via CartService::totals()).
 *   2. Translate cart items into OrderService::createOrder()'s existing
 *      input shape.
 *   3. Call OrderService::createOrder() UNCHANGED (Phase B5).
 *   4. Call PaymentService::createForOrder() UNCHANGED (Phase B7) —
 *      Architectural Decision (b7-inspection-findings.md): Order is
 *      created FIRST, then Payment, because OrderService is what
 *      performs inventory reservation/entitlement checks that must
 *      succeed before it makes sense to ask for payment.
 *   5. Mark the cart Converted, in the SAME outer transaction as order
 *      + payment creation (Step 13 "Transactional Consistency").
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
     * @throws \Illuminate\Validation\ValidationException
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

        // Module 12 §88 — checked BEFORE any order/inventory side
        // effect, so an unentitled payment method fails fast with no
        // partial state created.
        $this->entitlements->assertFeatureEntitled(self::FEATURE_KEY_BY_METHOD[$paymentMethod->value]);

        // Module 11 §46 "Final Validation" — reload authoritative
        // product/variant/price/availability ONE more time immediately
        // before order creation (the cart's live totals() already does
        // exactly this — reused, not duplicated).
        $totals = $this->carts->totals($cart);

        if ($totals['items'] === []) {
            throw new CartCheckoutNotAllowedException('Your cart is empty.');
        }

        if ($totals['has_issues']) {
            throw new CartCheckoutNotAllowedException('One or more items in your cart need attention before checkout can continue.');
        }

        $items = $cart->items->map(fn ($item) => [
            'product_id' => $item->product_variant_id === null ? $item->product_id : null,
            'product_variant_id' => $item->product_variant_id,
            'quantity' => $item->quantity,
        ])->all();

        return DB::transaction(function () use ($cart, $items, $checkoutData, $idempotencyKey, $paymentMethod) {
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
