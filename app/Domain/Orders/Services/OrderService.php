<?php

declare(strict_types=1);

namespace App\Domain\Orders\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\ReservationStatus;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Orders\Exceptions\EmptyOrderException;
use App\Domain\Orders\Models\CancellationReason;
use App\Domain\Orders\Models\FulfillmentStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Orders\Models\OrderTimelineEvent;
use App\Domain\Orders\Models\PaymentStatus as OrderPaymentStatus;
use App\Domain\Packages\Services\EntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Module 09 §19 "Order Creation Flow" + §107 Final Architectural Rules.
 * This is the ONLY code path that creates or transitions an Order —
 * no controller writes `order.status` or inventory reservations
 * directly (Final Rule #11: "confirmed orders must not be casually
 * mutated").
 *
 * B5's documented simplification of §19's full flow (no Payment module
 * exists yet — Module 12): every order created through this service
 * goes PendingConfirmation → Confirmed immediately (matching the COD
 * flow in §26: "Confirm Order → Reserve/Commit Inventory"), with stock
 * RESERVED (not yet deducted from on_hand) — actual commit/deduction is
 * deferred to whichever future module (Payment confirmation, or
 * Fulfillment) introduces a real "commit" step (Module 08 §48's
 * Reserved-vs-Committed distinction).
 */
final class OrderService
{
    private const FEATURE_KEY = 'orders.basic';
    private const USAGE_KEY = 'max_monthly_orders';

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly EntitlementService $entitlements,
        private readonly OrderNumberGenerator $numberGenerator,
        private readonly OrderStateMachine $stateMachine,
        private readonly RecordsOutboxEvents $outbox,
    ) {}

    /**
     * @param list<array{product_id?: int, product_variant_id?: int, quantity: int}> $items
     * @throws EmptyOrderException|ValidationException
     * @throws \App\Domain\Packages\Exceptions\FeatureNotEntitledException
     * @throws \App\Domain\Packages\Exceptions\SubscriptionInactiveException
     * @throws \App\Domain\Packages\Exceptions\UsageLimitExceededException
     * @throws \App\Domain\Inventory\Exceptions\InsufficientStockException
     */
    public function createOrder(array $items, array $orderData, string $idempotencyKey): Order
    {
        // Idempotency FIRST (Module 09 §23-24) — before any entitlement
        // or inventory side effect, so a retried request never even
        // re-attempts the usage-limit check.
        if ($existing = $this->findByIdempotencyKey($idempotencyKey)) {
            return $existing;
        }

        if ($items === []) {
            throw new EmptyOrderException();
        }

        // Module 09 §84 "Order Limits" via the SAME EntitlementService
        // every other module uses — no parallel limit-checking logic.
        $this->entitlements->assertCanUse(self::FEATURE_KEY, self::USAGE_KEY);

        return DB::transaction(function () use ($items, $orderData, $idempotencyKey) {
            $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

            $resolvedItems = array_map(
                fn (array $item) => $this->resolveAndPriceItem($item, $warehouse),
                $items
            );

            $currency = $resolvedItems[0]['currency'];
            foreach ($resolvedItems as $resolved) {
                if ($resolved['currency'] !== $currency) {
                    // Documented B5 decision: mixed-currency orders are
                    // not supported (Module 04/06's multi-currency
                    // architecture is a Premium future-expansion item,
                    // not yet built — see B2's architecture notes).
                    throw ValidationException::withMessages([
                        'items' => 'All items in an order must use the same currency.',
                    ]);
                }
            }

            $order = Order::query()->create([
                'order_number' => $this->numberGenerator->next(),
                'customer_id' => $orderData['customer_id'] ?? null,
                'guest_name' => $orderData['guest_name'] ?? null,
                'guest_email' => $orderData['guest_email'] ?? null,
                'guest_phone' => $orderData['guest_phone'] ?? null,
                'status' => OrderStatus::PendingConfirmation,
                'source' => $orderData['source'] ?? 'admin',
                'currency' => $currency,
                'billing_address_snapshot' => $orderData['billing_address'] ?? null,
                'shipping_address_snapshot' => $orderData['shipping_address'] ?? null,
                'notes' => $orderData['notes'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);

            $subtotal = 0;

            foreach ($resolvedItems as $index => $resolved) {
                // Module 08 §20-23: reserve BEFORE committing the order
                // item, inside the SAME transaction — if any line fails
                // (insufficient stock), the whole transaction rolls back,
                // including the Order row itself and every prior
                // reservation in this loop (Laravel/MySQL transaction
                // semantics) — no orphaned reservation, no partial order.
                $this->inventory->reserve(
                    $resolved['inventory'],
                    $resolved['quantity'],
                    idempotencyKey: "{$idempotencyKey}:item:{$index}",
                    referenceType: 'order',
                    referenceId: $order->id,
                );

                $lineTotal = $resolved['unit_price_minor'] * $resolved['quantity'];
                $subtotal += $lineTotal;

                // Phase B9 addition: line-targeted promotion discounts
                // (product/category/brand-scoped), computed by
                // PromotionEligibilityEngine and passed straight
                // through — OrderService never computes a discount
                // itself, it only applies an already-server-calculated
                // one. Absent for every pre-B9 caller (backward
                // compatible — defaults to 0).
                $lineDiscount = (int) ($orderData['line_discounts'][$index] ?? 0);

                $order->items()->create([
                    'product_id' => $resolved['product']?->id,
                    'product_variant_id' => $resolved['variant']?->id,
                    'product_name_snapshot' => $resolved['name_snapshot'],
                    'sku_snapshot' => $resolved['sku_snapshot'],
                    'variant_snapshot' => $resolved['variant_snapshot'],
                    'quantity' => $resolved['quantity'],
                    'unit_price_minor' => $resolved['unit_price_minor'],
                    'discount_minor' => $lineDiscount,
                    'line_total_minor' => $lineTotal - $lineDiscount,
                ]);
            }

            // Module 09 §12: Subtotal - Discounts + Tax + Shipping = Grand
            // Total. Tax is still 0 (no owning module yet — see
            // docs/development/b5-inspection-findings.md). Shipping
            // is now server-computed by CheckoutService/
            // ShippingRateService (Phase B8) and passed in via
            // orderData — this is the minimal, additive extension
            // Module 13's Step 18 anticipated; existing callers that
            // omit shipping_total_minor keep B5's original behavior
            // (grand_total == subtotal) unchanged.
            $shippingTotal = (int) ($orderData['shipping_total_minor'] ?? 0);
            $discountTotal = (int) ($orderData['discount_total_minor'] ?? 0);
            $order->update([
                'subtotal_minor' => $subtotal,
                'discount_total_minor' => $discountTotal,
                'shipping_total_minor' => $shippingTotal,
                'grand_total_minor' => $subtotal - $discountTotal + $shippingTotal,
            ]);

            $this->recordTimelineEvent($order, 'created', null, $order->status, null, null);

            // B5's documented auto-confirm simplification (see class
            // docblock) — no payment gateway exists yet to gate this.
            $this->transitionTo($order, OrderStatus::Confirmed, actorId: null, reason: 'auto_confirmed_no_payment_gateway');

            $this->entitlements->recordUsage(self::USAGE_KEY);

            $this->outbox->recordEvent(
                eventType: 'order.created',
                payload: ['order_id' => $order->id, 'order_number' => $order->order_number, 'grand_total_minor' => $subtotal - $discountTotal + $shippingTotal],
                idempotencyKey: "order:{$order->id}:created",
            );

            // NOTE: `load()`, not `fresh()` — fresh() would return a
            // brand-new model instance from a new query, which loses
            // Eloquent's wasRecentlyCreated flag. The controller relies
            // on that flag to return 201 vs 200 correctly for the
            // idempotent-replay case (see findByIdempotencyKey() above,
            // whose result is a genuinely fresh query and correctly
            // reports wasRecentlyCreated = false).
            return $order->load('items');
        });
    }

    /**
     * Module 09 §41-44 "Order Cancellation". Releases every ACTIVE
     * reservation tied to this order (Module 08 §44: "Reserved →
     * Release") — reuses B4's InventoryService::release(), never a
     * duplicate inventory implementation.
     *
     * @throws \App\Domain\Orders\Exceptions\OrderCancellationNotAllowedException
     */
    public function cancelOrder(Order $order, CancellationReason $reason, ?string $note, ?int $actorId): Order
    {
        $this->stateMachine->assertCancellable($order);

        return DB::transaction(function () use ($order, $reason, $note, $actorId) {
            $activeReservations = \App\Domain\Inventory\Models\StockReservation::query()
                ->where('reference_type', 'order')
                ->where('reference_id', $order->id)
                ->where('status', ReservationStatus::Active)
                ->get();

            foreach ($activeReservations as $reservation) {
                $this->inventory->release($reservation, ReservationStatus::Released);
            }

            $order->update([
                'cancellation_reason' => $reason,
                'cancellation_note' => $note,
                'cancelled_by' => $actorId,
                'cancelled_at' => now(),
            ]);

            $this->transitionTo($order, OrderStatus::Cancelled, $actorId, $reason->value, $note);

            $this->outbox->recordEvent(
                eventType: 'order.cancelled',
                payload: ['order_id' => $order->id, 'reason' => $reason->value],
                idempotencyKey: "order:{$order->id}:cancelled",
            );

            return $order->fresh();
        });
    }

    /**
     * Centralized status transition — the ONLY place `orders.status` is
     * written (Module 09 Step 5: "no controller-only state enforcement").
     */
    /**
     * Phase B7 addition. The ONLY code path that writes
     * Order.payment_status — called exclusively by
     * App\Domain\Payments\Services\PaymentService after a Payment's
     * own (much more detailed, Module 12 §7) state changes. This
     * method does NOT re-validate the transition itself
     * (PaymentService/PaymentStateMachine already did, against the
     * PAYMENT's state graph) — it exists so no controller or webhook
     * handler ever calls `$order->update(['payment_status' => ...])`
     * directly, keeping Order's own "dumb model, service-only writes"
     * invariant (established since Phase B5) intact for this field too.
     */
    public function syncPaymentStatus(Order $order, OrderPaymentStatus $status): void
    {
        $order->update(['payment_status' => $status]);

        $this->recordTimelineEvent(
            $order, 'payment_status_changed', null, null, actorId: null,
            reason: "payment_status:{$status->value}",
        );
    }

    /**
     * Phase B8 addition, mirroring syncPaymentStatus()'s exact pattern.
     * The ONLY code path that writes Order.fulfillment_status — called
     * exclusively by App\Domain\Shipping\Services\ShipmentService.
     */
    public function syncFulfillmentStatus(Order $order, FulfillmentStatus $status): void
    {
        $order->update(['fulfillment_status' => $status]);

        $this->recordTimelineEvent(
            $order, 'fulfillment_status_changed', null, null, actorId: null,
            reason: "fulfillment_status:{$status->value}",
        );
    }

    private function transitionTo(Order $order, OrderStatus $to, ?int $actorId, ?string $reason, ?string $note = null): void
    {
        $this->stateMachine->assertCanTransition($order->status, $to);

        $from = $order->status;
        $order->update(['status' => $to]);

        $this->recordTimelineEvent($order, 'status_changed', $from, $to, $actorId, $reason, $note);
    }

    private function recordTimelineEvent(
        Order $order,
        string $eventType,
        ?OrderStatus $from,
        ?OrderStatus $to,
        ?int $actorId,
        ?string $reason,
        ?string $note = null,
    ): void {
        OrderTimelineEvent::query()->create([
            'order_id' => $order->id,
            'event_type' => $eventType,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'actor_id' => $actorId,
            'reason' => $reason,
            'note' => $note,
        ]);
    }

    /**
     * Phase B9 addition — lets CheckoutService detect an idempotent
     * replay BEFORE re-running promotion evaluation (see that class's
     * docblock for why this matters: promotions, unlike shipping
     * rates, can have a usage limit the original request itself may
     * have just consumed). Read-only; never creates anything.
     */
    public function findExistingOrderByIdempotencyKey(string $key): ?Order
    {
        return $this->findByIdempotencyKey($key);
    }

    private function findByIdempotencyKey(string $key): ?Order
    {
        return Order::query()->where('idempotency_key', $key)->first();
    }

    /**
     * Module 09 §20-21 "Server-Side Validation / Client Data Trust":
     * loads the product/variant SERVER-SIDE and prices from
     * effectivePriceMinor() — the client's `quantity` is the only input
     * trusted verbatim (and even that is validated against available
     * stock via InventoryService::reserve()'s atomic guard).
     *
     * @return array{product: ?Product, variant: ?ProductVariant, inventory: Inventory, quantity: int, unit_price_minor: int, currency: string, name_snapshot: string, sku_snapshot: ?string, variant_snapshot: ?array}
     * @throws ValidationException
     */
    private function resolveAndPriceItem(array $item, Warehouse $warehouse): array
    {
        $quantity = (int) ($item['quantity'] ?? 0);

        if ($quantity <= 0) {
            throw ValidationException::withMessages(['items' => 'Each item quantity must be at least 1.']);
        }

        $product = null;
        $variant = null;

        if (! empty($item['product_variant_id'])) {
            // Tenant-scoped find (BelongsToTenant) — a cross-tenant
            // variant ID never resolves, mirroring Phase B3/B4's
            // identical cross-tenant relation-validation pattern.
            $variant = ProductVariant::query()->with('product')->find($item['product_variant_id']);

            if ($variant === null) {
                throw ValidationException::withMessages(['items' => 'One or more selected variants do not exist in this store.']);
            }

            $product = $variant->product;
            $unitPrice = $variant->effectivePriceMinor();
            $nameSnapshot = $product->name;
            $skuSnapshot = $variant->sku ?? $product->sku;
            $variantSnapshot = $variant->option_values;
            $currency = $product->currency;
        } elseif (! empty($item['product_id'])) {
            $product = Product::query()->find($item['product_id']);

            if ($product === null) {
                throw ValidationException::withMessages(['items' => 'One or more selected products do not exist in this store.']);
            }

            $unitPrice = $product->effectivePriceMinor();
            $nameSnapshot = $product->name;
            $skuSnapshot = $product->sku;
            $variantSnapshot = null;
            $currency = $product->currency;
        } else {
            throw ValidationException::withMessages(['items' => 'Each item must reference a product or a product variant.']);
        }

        if ($unitPrice === null) {
            throw ValidationException::withMessages(['items' => 'The selected product does not have a price configured.']);
        }

        $inventory = Inventory::query()
            ->where('warehouse_id', $warehouse->id)
            ->when(
                $variant !== null,
                fn ($q) => $q->where('product_variant_id', $variant->id),
                fn ($q) => $q->where('product_id', $product->id)->whereNull('product_variant_id')
            )
            ->first();

        if ($inventory === null) {
            // Documented decision (docs/development/b5-inspection-findings.md):
            // no Inventory record for this SKU in the default warehouse
            // means it has never had opening stock set — treated as
            // out of stock rather than auto-vivifying a phantom record.
            throw ValidationException::withMessages(['items' => 'One or more items are currently unavailable.']);
        }

        return [
            'product' => $product,
            'variant' => $variant,
            'inventory' => $inventory,
            'quantity' => $quantity,
            'unit_price_minor' => $unitPrice,
            'currency' => $currency,
            'name_snapshot' => $nameSnapshot,
            'sku_snapshot' => $skuSnapshot,
            'variant_snapshot' => $variantSnapshot,
        ];
    }
}
