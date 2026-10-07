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
use App\Domain\Promotions\Exceptions\CouponNotEligibleException;
use App\Domain\Promotions\Services\PromotionEligibilityEngine;
use App\Domain\Promotions\Services\PromotionService;
use App\Domain\Shipping\Exceptions\DestinationNotServiceableException;
use App\Domain\Shipping\Services\ShippingRateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Module 11 §12/§42-58 "Checkout" + Module 12 §7 "Payment Initiation" +
 * Module 13 §18/§38 "Shipping Cost / Rate Engine" + Module 14 §56
 * "Promotion Eligibility Engine". A thin orchestration layer — this
 * class contains NO order-creation, pricing, inventory, payment-
 * gateway, shipping-rate, or promotion-calculation logic of its own.
 * Its entire job is:
 *   1. Final cart-level validation (via CartService::totals()).
 *   2. If the cart is not digital-only, compute a server-authoritative
 *      shipping quote (via ShippingRateService::quote() — Phase B8,
 *      UNCHANGED here).
 *   3. Evaluate promotions/coupon (via
 *      PromotionEligibilityEngine::evaluate() — Phase B9, UNCHANGED
 *      here) using the cart's stored coupon_code, folding the result
 *      into the Order total and, for free shipping, zeroing the
 *      shipping cost.
 *   4. Translate cart items into OrderService::createOrder()'s existing
 *      input shape.
 *   5. Call OrderService::createOrder() (Phase B5, extended additively
 *      in B8/B9 to accept `shipping_total_minor`/`discount_total_minor`/
 *      `line_discounts` — see that class).
 *   6. Call PaymentService::createForOrder() UNCHANGED (Phase B7).
 *   7. Record promotion usage — ONLY when the Order was genuinely just
 *      created (never on an idempotent replay).
 *   8. Mark the cart Converted, in the SAME outer transaction as order
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
        private readonly PromotionEligibilityEngine $promotionEngine,
        private readonly PromotionService $promotions,
    ) {}

    /** Module 12 §88 "Package Entitlements" — every tier is entitled to all 3 B7 methods by default (see docs/development/b7-inspection-findings.md); the check exists so a future tier restriction is configurable without a code change. */
    public const FEATURE_KEY_BY_METHOD = [
        'cod' => 'payment.cod',
        'bank_transfer' => 'payment.bank_transfer',
        'mock_redirect' => 'payment.online',
    ];

    /**
     * @return array{order: Order, payment: Payment, redirect_url: ?string}
     * @throws CartCheckoutNotAllowedException
     * @throws ValidationException
     * @throws DestinationNotServiceableException
     * @throws CouponNotEligibleException
     * @throws \App\Domain\Packages\Exceptions\FeatureNotEntitledException
     * @throws \App\Domain\Packages\Exceptions\SubscriptionInactiveException
     * @throws \App\Domain\Packages\Exceptions\UsageLimitExceededException
     * @throws \App\Domain\Inventory\Exceptions\InsufficientStockException
     * @throws \App\Domain\Payments\Exceptions\PaymentAlreadyExistsException
     * @throws \App\Domain\Promotions\Exceptions\PromotionUsageLimitExceededException
     */
    public function checkout(Cart $cart, PaymentMethod $paymentMethod, array $checkoutData, string $idempotencyKey): array
    {
        if (! $cart->status->isActionable()) {
            throw new CartCheckoutNotAllowedException('This cart is no longer active and cannot be checked out.');
        }

        // Module 10 §31 (Phase B32): a blocked customer places no new orders,
        // signed in or as a guest with the same email.
        // The address is checked too, so another record with it (an account
        // made before the block, a guest) cannot be used to get round it.
        $orderEmail = mb_strtolower(trim((string) ($cart->customer !== null ? $cart->customer->email : ($checkoutData['guest_email'] ?? ''))));
        $blocked = ($cart->customer !== null && ! $cart->customer->standing()->mayOrder())
            || ($orderEmail !== '' && \App\Domain\Orders\Models\Customer::query()
                ->whereRaw('LOWER(email) = ?', [$orderEmail])->where('status', \App\Domain\Customers\Models\CustomerStatus::Blocked->value)->exists());
        if ($blocked) {
            throw new CartCheckoutNotAllowedException('This order cannot be placed. Please contact the store.');
        }

        // Phase B9 correctness fix: an idempotent REPLAY must short-
        // circuit before any promotion evaluation runs. Promotions
        // (unlike shipping rates) can have a finite usage_limit that
        // the ORIGINAL request itself may have just fully consumed —
        // without this check, a legitimate retry of an already-
        // successful checkout could re-run PromotionEligibilityEngine
        // and incorrectly throw (e.g. CouponNotEligibleException,
        // "usage limit reached") even though the first request already
        // succeeded and a valid Order/Payment already exist. Caught
        // during design, before being left in the codebase.
        if ($existing = $this->orders->findExistingOrderByIdempotencyKey($idempotencyKey)) {
            $payment = \App\Domain\Payments\Models\Payment::query()->where('order_id', $existing->id)->first();

            return ['order' => $existing, 'payment' => $payment, 'redirect_url' => $payment !== null ? $this->payments->redirectUrlFor($payment) : null];
        }

        $this->entitlements->assertFeatureEntitled('shipping.basic');
        $this->entitlements->assertFeatureEntitled(self::FEATURE_KEY_BY_METHOD[$paymentMethod->value]);

        [
            'shipping_minor' => $shippingTotalMinor,
            'discount_minor' => $discountTotalMinor,
            'promotion' => $promotionResult,
        ] = $this->price($cart, $checkoutData, requireShipping: true);
        $items = $cart->items->map(fn ($item) => [
            'product_id' => $item->product_variant_id === null ? $item->product_id : null,
            'product_variant_id' => $item->product_variant_id,
            'quantity' => $item->quantity,
        ])->all();

        return DB::transaction(function () use ($cart, $items, $checkoutData, $idempotencyKey, $paymentMethod, $shippingTotalMinor, $discountTotalMinor, $promotionResult) {
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
                    'discount_total_minor' => $discountTotalMinor,
                    'line_discounts' => $promotionResult->lineDiscounts,
                ],
                idempotencyKey: $idempotencyKey,
            );

            // Usage is recorded ONLY on genuine creation (Architectural
            // Decision — see b9-inspection-findings.md "Usage Counting
            // Timing") — never re-consumed on an idempotent replay.
            if ($order->wasRecentlyCreated) {
                $this->promotions->recordUsage($promotionResult, $order, $cart->customer);
            }

            // Phase B34 (Module 12 §13): a signed-in customer may pay with
            // their store credit. The amount is the server's: as much of the
            // total as the balance covers, taken under a lock on the balance,
            // once (only when the order was really created now).
            if ($order->wasRecentlyCreated && ! empty($checkoutData['use_store_credit']) && $cart->customer !== null) {
                $used = app(\App\Domain\StoreCredit\Services\StoreCreditService::class)->spendOnOrder($cart->customer, $order);
                if ($used > 0) {
                    $this->orders->applyStoreCredit($order, $used);
                }
            }

            // Payment idempotency key is derived from the order-level
            // key (never the client's raw value reused verbatim as a
            // second, unrelated idempotency scope) — same
            // "{key}:sub-scope" convention already used for per-line-
            // item reservation keys in OrderService (Phase B5).
            $payment = $this->payments->createForOrder($order, $paymentMethod, "{$idempotencyKey}:payment");
            // The payment may have settled the order at once (nothing left to
            // pay): the answer must show the order as it is now. `refresh()`
            // keeps wasRecentlyCreated, which the controller needs for 201/200.
            $order->refresh();

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

    /**
     * Phase B46 (Module 11 §12/§28, Module 05 §43): what the order would
     * cost, before it is placed — subtotal, discount, shipping, tax and
     * total — from the same pricing and the same tax rules as checkout()
     * and OrderService. Nothing is reserved, recorded or created.
     *
     * @param array<string, mixed> $checkoutData shipping_address, billing_address, shipping_method_id
     * @return array<string, mixed>
     */
    public function summary(Cart $cart, array $checkoutData): array
    {
        $priced = $this->price($cart, $checkoutData, requireShipping: false);
        $lines = [];
        foreach ($priced['cart_items']->values() as $index => $item) {
            $line = collect($priced['totals']['items'])->firstWhere('cart_item_id', $item->id);
            $lines[] = [
                'key' => 'line'.$index,
                'gross_minor' => (int) ($line['line_total_minor'] ?? 0),
                'discount_minor' => (int) ($priced['promotion']->lineDiscounts[$index] ?? 0),
                'tax_class_id' => $item->product?->tax_class_id,
            ];
        }
        $orderDiscount = max(0, $priced['discount_minor'] - array_sum(array_column($lines, 'discount_minor')));
        $tax = app(\App\Domain\Tax\Services\TaxService::class)->quote(
            $lines, $orderDiscount, $priced['shipping_minor'],
            $checkoutData['shipping_address'] ?? null, $checkoutData['billing_address'] ?? null, $cart->customer,
        );
        $subtotal = (int) $priced['totals']['subtotal_minor'];

        return [
            'currency' => $priced['totals']['currency'] ?? $this->carts->storeCurrency(),
            'subtotal_minor' => $subtotal,
            'discount_minor' => $priced['discount_minor'],
            'shipping_minor' => $priced['needs_shipping_method'] ? null : $priced['shipping_minor'],
            'needs_shipping_method' => $priced['needs_shipping_method'],
            'tax' => [
                'enabled' => $tax['enabled'], 'label' => $tax['label'], 'prices_include_tax' => $tax['prices_include_tax'],
                'total_minor' => $tax['total_minor'], 'shipping_minor' => $tax['shipping_minor'], 'breakdown' => $tax['breakdown'],
                'exempt' => (bool) ($tax['snapshot']['exempt'] ?? false),
            ],
            'grand_total_minor' => $subtotal - $priced['discount_minor'] + $priced['shipping_minor'] + ($tax['prices_include_tax'] ? 0 : $tax['total_minor']),
        ];
    }

    /**
     * Shipping and promotions for a cart, server-side (Module 13 §39, Module
     * 14 §56) — shared by checkout() and summary() so both price an order the
     * same way. checkout() needs a shipping method for physical goods; the
     * summary may come before one is chosen.
     *
     * @param array<string, mixed> $checkoutData
     * @return array{totals: array<string, mixed>, cart_items: \Illuminate\Support\Collection<int, \App\Domain\Cart\Models\CartItem>, shipping_minor: int, discount_minor: int, promotion: \App\Domain\Promotions\Services\PromotionEvaluationResult, needs_shipping_method: bool}
     */
    private function price(Cart $cart, array $checkoutData, bool $requireShipping): array
    {
        $needsMethod = false;
        $totals = $this->carts->totals($cart);

        if ($totals['items'] === []) {
            throw new CartCheckoutNotAllowedException('Your cart is empty.');
        }

        if ($totals['has_issues']) {
            throw new CartCheckoutNotAllowedException('One or more items in your cart need attention before checkout can continue.');
        }

        $cartItems = $cart->items->load(['product.categories', 'variant']);

        // Module 13 Final Rule #8: "Digital-only carts must not require
        // physical shipping." Reuses Phase B3's existing ProductType —
        // no new "requires_shipping" column was added.
        $isDigitalOnly = $cartItems->every(
            fn ($item) => $item->product !== null && in_array($item->product->type, [ProductType::Digital, ProductType::Service], true)
        );

        $shippingTotalMinor = 0;

        if (! $isDigitalOnly && ! $requireShipping && empty($checkoutData['shipping_method_id'])) {
            $needsMethod = true; // the summary before a method is chosen: no shipping yet
        } elseif (! $isDigitalOnly) {
            if (empty($checkoutData['shipping_method_id'])) {
                throw ValidationException::withMessages(['shipping_method_id' => 'A shipping method is required for this order.']);
            }
            if (empty($checkoutData['shipping_address']['country'] ?? null)) {
                throw ValidationException::withMessages(['shipping_address' => 'A shipping destination (at least a country) is required.']);
            }

            $weightedItems = $cartItems->map(fn ($item) => [
                'weight' => (float) ($item->variant->weight ?? 0) * $item->quantity,
            ])->all();

            // Module 13 §39 "Server-Authoritative Rate" — the client
            // may REQUEST a method (shipping_method_id), never a price;
            // this call is the sole source of the actual cost.
            $quote = $this->shippingRates->quote(
                (int) $checkoutData['shipping_method_id'],
                $checkoutData['shipping_address'],
                $totals['subtotal_minor'],
                $weightedItems,
                $totals['currency'] ?? $this->carts->storeCurrency(),
            );

            $shippingTotalMinor = $quote['cost_minor'];
        }

        // Module 14 §56 "Promotion Eligibility Engine" — evaluated with
        // the ACTUAL shipping cost so a free_shipping promotion's
        // benefit is compared on equal footing with a cash discount
        // (see PromotionEligibilityEngine::shippingBenefitValue()).
        $promotionItemContexts = $cartItems->map(fn ($item) => [
            'product_id' => $item->product_id,
            'category_ids' => $item->product?->categories->pluck('id')->all() ?? [],
            'brand_id' => $item->product?->brand_id,
            'collection_ids' => $item->product_id !== null ? app(\App\Domain\Catalog\Services\CollectionService::class)->promotionCollectionIdsFor($item->product_id) : [],
            'line_total_minor' => $totals['items'][array_search($item->id, array_column($totals['items'], 'cart_item_id'), true)]['line_total_minor'] ?? 0,
        ])->values()->all();

        $promotionResult = $this->promotionEngine->evaluate(
            $promotionItemContexts, $totals['subtotal_minor'], $totals['currency'] ?? $this->carts->storeCurrency(),
            $cart->customer, $cart->coupon_code, $shippingTotalMinor,
        );

        $discountTotalMinor = $promotionResult->freeShipping ? 0 : $promotionResult->discountAmountMinor;

        if ($promotionResult->freeShipping) {
            $shippingTotalMinor = 0;
        }

        return [
            'totals' => $totals, 'cart_items' => $cartItems, 'shipping_minor' => $shippingTotalMinor, 'discount_minor' => $discountTotalMinor,
            'promotion' => $promotionResult, 'needs_shipping_method' => $needsMethod,
        ];
    }
}
