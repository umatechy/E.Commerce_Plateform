<?php

declare(strict_types=1);

namespace App\Domain\Cart\Services;

use App\Domain\Cart\Exceptions\CartCheckoutNotAllowedException;
use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderService;
use Illuminate\Support\Facades\DB;

/**
 * Module 11 §12/§42-58 "Checkout". A thin orchestration layer — this
 * class contains NO order-creation, pricing, or inventory logic of its
 * own. Its entire job is:
 *   1. Final cart-level validation (via CartService::totals()).
 *   2. Translate cart items into OrderService::createOrder()'s existing
 *      input shape.
 *   3. Call OrderService::createOrder() UNCHANGED (Phase B5).
 *   4. Mark the cart Converted, in the SAME outer transaction as order
 *      creation (Step 13 "Transactional Consistency" — a crash between
 *      "order created" and "cart marked converted" would otherwise
 *      leave the cart incorrectly still Active).
 *
 * No persisted CheckoutSession entity exists in B6 — see
 * docs/development/b6-inspection-findings.md "Scope Decision" for why
 * this is a documented simplification, not an oversight.
 */
final class CheckoutService
{
    public function __construct(
        private readonly CartService $carts,
        private readonly OrderService $orders,
    ) {}

    /**
     * @throws CartCheckoutNotAllowedException
     * @throws \Illuminate\Validation\ValidationException
     * @throws \App\Domain\Packages\Exceptions\FeatureNotEntitledException
     * @throws \App\Domain\Packages\Exceptions\SubscriptionInactiveException
     * @throws \App\Domain\Packages\Exceptions\UsageLimitExceededException
     * @throws \App\Domain\Inventory\Exceptions\InsufficientStockException
     */
    public function checkout(Cart $cart, array $checkoutData, string $idempotencyKey): Order
    {
        if (! $cart->status->isActionable()) {
            throw new CartCheckoutNotAllowedException('This cart is no longer active and cannot be checked out.');
        }

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

        return DB::transaction(function () use ($cart, $items, $checkoutData, $idempotencyKey) {
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

            if ($cart->status === CartStatus::Active) {
                $cart->update([
                    'status' => CartStatus::Converted,
                    'converted_at' => now(),
                    'converted_order_id' => $order->id,
                ]);
            }

            return $order;
        });
    }
}
