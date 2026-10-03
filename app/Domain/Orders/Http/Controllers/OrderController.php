<?php

declare(strict_types=1);

namespace App\Domain\Orders\Http\Controllers;

use App\Domain\Orders\Exceptions\EmptyOrderException;
use App\Domain\Orders\Exceptions\OrderCancellationNotAllowedException;
use App\Domain\Orders\Http\Requests\CancelOrderRequest;
use App\Domain\Orders\Http\Requests\CreateOrderRequest;
use App\Domain\Orders\Http\Resources\OrderResource;
use App\Domain\Orders\Http\Resources\OrderTimelineEventResource;
use App\Domain\Orders\Models\CancellationReason;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Orders\Models\PaymentStatus;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Packages\Exceptions\FeatureNotEntitledException;
use App\Domain\Packages\Exceptions\SubscriptionInactiveException;
use App\Domain\Packages\Exceptions\UsageLimitExceededException;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Staff-facing Order API (ADR-005 /api/v1/...). Every method:
 * authenticated (route middleware), authorized (Gate below),
 * tenant-scoped (BelongsToTenant + OrderService's internal tenant-
 * scoped catalog/inventory lookups), validated (FormRequests),
 * idempotent where mutating (OrderService).
 */
final class OrderController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('viewAny', Order::class);

        // Phase B31 (G6): the admin order list filters and searches on the server.
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'payment_status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'search' => ['nullable', 'string', 'max:255'],
            'customer' => ['nullable', 'string', 'size:26'], // Phase B32: one customer's orders
        ]);

        return OrderResource::collection(
            Order::query()->with(['items', 'customer'])
                ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
                ->when($filters['payment_status'] ?? null, fn ($q, string $status) => $q->where('payment_status', $status))
                ->when($filters['customer'] ?? null, fn ($q, string $customer) => $q->whereHas('customer', fn ($c) => $c->where('public_id', $customer)))
                ->when($filters['search'] ?? null, function ($query, string $search) {
                    $like = '%'.addcslashes($search, '%_\\').'%';
                    $query->where(fn ($q) => $q->where('order_number', 'like', $like)->orWhere('guest_name', 'like', $like)->orWhere('guest_email', 'like', $like));
                })
                ->orderByDesc('created_at')->orderByDesc('id')
                ->paginate(25)
        );
    }

    public function show(Request $request, Order $order): OrderResource
    {
        Gate::forUser($request->user())->authorize('view', $order);

        return new OrderResource($order->load(['items', 'customer']));
    }

    public function store(CreateOrderRequest $request, OrderService $orders): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', Order::class);

        // Phase B32 security fix: a customer id from another store was accepted
        // (the `exists` rule has no tenant). Only this store's customers, by
        // public id or numeric key; a blocked customer places no new orders.
        $customer = null;
        if ($request->filled('customer') || $request->filled('customer_id')) {
            $customer = \App\Domain\Orders\Models\Customer::query()
                ->when($request->filled('customer'), fn ($q) => $q->where('public_id', $request->string('customer')->toString()), fn ($q) => $q->whereKey((int) $request->input('customer_id')))
                ->whereNull('erased_at')
                ->first();
            if ($customer === null) {
                throw ValidationException::withMessages(['customer' => 'Choose one of your store\'s customers.']);
            }
            if (! $customer->standing()->mayOrder()) {
                throw ValidationException::withMessages(['customer' => 'This customer is '.$customer->standing()->value.' and cannot place orders.']);
            }
        }

        // Module 10 §31: nor as a guest with the address of a blocked customer.
        if ($customer === null && $request->filled('guest_email') && \App\Domain\Orders\Models\Customer::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($request->string('guest_email')->toString()))])
            ->where('status', \App\Domain\Customers\Models\CustomerStatus::Blocked->value)->exists()) {
            throw ValidationException::withMessages(['guest_email' => 'A blocked customer has this email. Unblock them first if they may order again.']);
        }

        $paymentMethod = $request->filled('payment_method') ? \App\Domain\Payments\Models\PaymentMethod::from($request->string('payment_method')->toString()) : null;
        if ($paymentMethod !== null) {
            try {
                app(\App\Domain\Packages\Services\EntitlementService::class)->assertFeatureEntitled(\App\Domain\Cart\Services\CheckoutService::FEATURE_KEY_BY_METHOD[$paymentMethod->value]);
            } catch (FeatureNotEntitledException|SubscriptionInactiveException $e) {
                return response()->json(['message' => $e->getMessage()], 403);
            }
        }

        try {
            $order = $orders->createOrder(
                items: $request->input('items'),
                orderData: [
                    ...$request->only(['guest_name', 'guest_email', 'guest_phone', 'billing_address', 'shipping_address', 'notes', 'source']),
                    'customer_id' => $customer?->id,
                ],
                idempotencyKey: $request->string('idempotency_key')->toString(),
            );
        } catch (EmptyOrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (FeatureNotEntitledException|SubscriptionInactiveException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (UsageLimitExceededException $e) {
            return response()->json([
                'message' => "You've reached your plan's monthly order limit ({$e->limit}). Upgrade your package to accept more orders.",
                'code' => 'usage_limit_exceeded',
            ], 403);
        } catch (InsufficientStockException $e) {
            return response()->json([
                'message' => 'One or more items in this order are out of stock.',
                'code' => 'insufficient_stock',
            ], 422);
        }

        $wasJustCreated = $order->wasRecentlyCreated;

        // The payment record (idempotent with the order's own key): from now
        // on staff record the money received, and a cash-on-delivery order
        // can be shipped before it is paid, as at checkout.
        if ($paymentMethod !== null) {
            app(\App\Domain\Payments\Services\PaymentService::class)->createForOrder($order, $paymentMethod, $request->string('idempotency_key')->toString().':payment');
            $order->refresh();
        }

        return (new OrderResource($order->load(['items', 'customer'])))->response()->setStatusCode($wasJustCreated ? 201 : 200);
    }

    public function cancel(CancelOrderRequest $request, Order $order, OrderService $orders): JsonResponse
    {
        Gate::forUser($request->user())->authorize('cancel', $order);

        try {
            $cancelled = $orders->cancelOrder(
                $order,
                reason: CancellationReason::from($request->string('reason')->toString()),
                note: $request->input('note'),
                actorId: $request->user()->id,
            );
        } catch (OrderCancellationNotAllowedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'cancellation_not_allowed'], 422);
        }

        return (new OrderResource($cancelled))->response();
    }

    public function timeline(Request $request, Order $order): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('view', $order);

        return OrderTimelineEventResource::collection(
            $order->timelineEvents()->orderByDesc('created_at')->get()
        );
    }
}
