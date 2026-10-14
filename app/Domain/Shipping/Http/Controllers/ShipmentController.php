<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Http\Controllers;

use App\Domain\Orders\Models\Order;
use App\Domain\Shipping\Couriers\CourierRegistry;
use App\Domain\Shipping\Exceptions\FulfillmentNotAllowedException;
use App\Domain\Shipping\Exceptions\InvalidShipmentStateTransitionException;
use App\Domain\Shipping\Exceptions\ShipmentQuantityExceedsOrderedException;
use App\Domain\Shipping\Http\Requests\CreateShipmentRequest;
use App\Domain\Shipping\Http\Requests\UpdateShipmentStatusRequest;
use App\Domain\Shipping\Http\Resources\ShipmentResource;
use App\Domain\Shipping\Http\Resources\ShipmentTrackingEventResource;
use App\Domain\Shipping\Models\CourierAccount;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\Models\ShipmentStatus;
use App\Domain\Shipping\Services\CourierShippingService;
use App\Domain\Shipping\Services\ShipmentService;
use App\Domain\Shipping\Services\ShippingLabelService;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Staff-facing Shipment API (Module 13 §75/§81). Every method:
 * authenticated (staff.principal, via route group), authorized
 * (Gate::authorize below), tenant-scoped, validated where mutating.
 */
final class ShipmentController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('viewAny', Shipment::class);

        // Phase B31 (G6): each shipment names its order; an order page lists its own.
        $filters = $request->validate([
            'order' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', Rule::enum(ShipmentStatus::class)],
            // Phase B49: what needs someone (a booking that did not go through, a failed delivery,
            // a parcel back at the store not yet in stock) and the cash couriers owe the store.
            'view' => ['nullable', 'in:attention,cod,returns'],
        ]);

        return ShipmentResource::collection(
            Shipment::query()->with(['items.orderItem', 'order'])
                ->when(($filters['view'] ?? null) === 'attention', fn ($q) => $q->where(fn ($w) => $w
                    ->where(fn ($b) => $b->whereIn('booking_status', ['failed', 'unknown'])->whereNotIn('status', ['cancelled']))
                    ->orWhere('status', ShipmentStatus::DeliveryFailed->value)
                    ->orWhere(fn ($r) => $r->where('status', ShipmentStatus::Returned->value)->where('direction', Shipment::OUTBOUND)->whereNull('restocked_at'))))
                ->when(($filters['view'] ?? null) === 'cod', fn ($q) => $q->where('cod_status', 'to_remit'))
                ->when(($filters['view'] ?? null) === 'returns', fn ($q) => $q->where('direction', Shipment::RETURN))
                ->when($filters['order'] ?? null, fn ($q, string $order) => $q->whereHas('order', fn ($o) => $o->where('public_id', $order)))
                ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
                ->orderByDesc('created_at')->orderByDesc('id')
                ->paginate(25)
        );
    }

    public function show(Request $request, Shipment $shipment): ShipmentResource
    {
        Gate::forUser($request->user())->authorize('view', $shipment);

        return new ShipmentResource($shipment->load(['items.orderItem', 'trackingEvents', 'order']));
    }

    public function store(CreateShipmentRequest $request, ShipmentService $shipments): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', Shipment::class);

        // Tenant-scoped lookup by public_id (never the internal id) —
        // BelongsToTenant's global scope makes a cross-tenant order_id
        // simply not found (404), mirroring every other cross-tenant
        // reference check in this codebase since Phase B3.
        $order = Order::query()->where('public_id', $request->string('order_id')->toString())->firstOrFail();

        // Phase B49: a connected courier must be usable by this store now.
        $carrier = $request->string('carrier')->toString();
        $account = null;
        if (app(CourierRegistry::class)->find($carrier) !== null) {
            $account = CourierAccount::query()->where('provider', $carrier)->first();
            if ($account === null || ! app(CourierShippingService::class)->usable($account)) {
                return response()->json(['message' => 'This courier is not connected and switched on for your store (Shipping setup).', 'code' => 'courier_not_available'], 422);
            }
        }

        try {
            $shipment = $shipments->createShipment(
                $order,
                warehouseId: (int) $request->input('warehouse_id'),
                carrier: $carrier,
                items: $request->input('items'),
                shippingMethodId: $request->input('shipping_method_id'),
                pickupLocationId: $request->input('pickup_location_id'),
                idempotencyKey: $request->string('idempotency_key')->toString(),
                options: [
                    'courier_account_id' => $account?->id,
                    'carrier_name' => $request->input('carrier_name'),
                    'tracking_number' => $request->input('tracking_number'),
                    'weight_grams' => $request->filled('weight_grams') ? (int) $request->input('weight_grams') : null,
                    'pieces' => $request->filled('pieces') ? (int) $request->input('pieces') : null,
                ],
            );
            // Asked after the shipment and its stock movements are stored (the courier call is outside the transaction).
            if ($shipment->wasRecentlyCreated && $shipment->booking_status === 'booking') {
                $created = true;
                $shipment = app(CourierShippingService::class)->book($shipment);
                $shipment->wasRecentlyCreated = $created;
            }
        } catch (FulfillmentNotAllowedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'fulfillment_not_allowed'], 422);
        } catch (ShipmentQuantityExceedsOrderedException $e) {
            return response()->json([
                'message' => $e->getMessage(), 'code' => 'quantity_exceeds_ordered', 'remaining' => $e->remaining,
            ], 422);
        }

        return (new ShipmentResource($shipment))->response()->setStatusCode($shipment->wasRecentlyCreated ? 201 : 200);
    }

    public function updateStatus(UpdateShipmentStatusRequest $request, Shipment $shipment, ShipmentService $shipments): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', $shipment);

        try {
            $shipments->updateStatus(
                $shipment,
                to: ShipmentStatus::from($request->string('status')->toString()),
                actorId: $request->user()->id,
                description: $request->input('description'),
            );
        } catch (InvalidShipmentStateTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_transition'], 422);
        }

        return (new ShipmentResource($shipment->fresh()))->response();
    }

    /** Phase B49: send the booking to the courier again (after a refusal, or after checking an unanswered one). */
    public function book(Request $request, Shipment $shipment, CourierShippingService $couriers): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', $shipment);
        if (! in_array($shipment->booking_status, ['failed', 'unknown'], true)) {
            return response()->json(['message' => 'This shipment does not need booking.', 'code' => 'not_bookable'], 409);
        }
        $this->audit($request, 'shipping.booking_retried', $shipment);

        return $this->one($couriers->book($shipment));
    }

    /** Phase B49 (Module 13 §72): cancel before pickup — with the courier first; units back in stock for the order. */
    public function cancel(Request $request, Shipment $shipment, CourierShippingService $couriers): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', $shipment);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
        try {
            $shipment = $couriers->cancel($shipment, $request->user()->id, $note);
        } catch (FulfillmentNotAllowedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'cancel_refused'], 409);
        } catch (InvalidShipmentStateTransitionException) {
            return response()->json(['message' => 'This shipment has left already and cannot be cancelled.', 'code' => 'invalid_transition'], 422);
        }

        return $this->one($shipment);
    }

    /** Phase B49 (Module 13 §54): read the courier's tracking now. */
    public function refresh(Request $request, Shipment $shipment, CourierShippingService $couriers): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $shipment);

        return $this->one($couriers->refresh($shipment));
    }

    /** Phase B49: a parcel returned to sender is back on the shelf. */
    public function restock(Request $request, Shipment $shipment, ShipmentService $shipments): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', $shipment);
        try {
            return $this->one($shipments->restockReturned($shipment, $request->user()->id));
        } catch (FulfillmentNotAllowedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'restock_refused'], 409);
        }
    }

    /** Phase B49: "another courier" — the courier's name and tracking number, typed by the store. */
    public function setTracking(Request $request, Shipment $shipment, ShipmentService $shipments): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', $shipment);
        $data = $request->validate([
            'carrier_name' => ['required', 'string', 'max:64'],
            'tracking_number' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-\/ ]+$/', Rule::unique('shipments', 'tracking_number')->where('store_id', $shipment->store_id)->ignore($shipment->id)],
        ]);
        if ($shipment->carrier !== CourierRegistry::MANUAL || $shipment->status->isTerminal()) {
            return response()->json(['message' => 'Only a shipment with another courier takes a typed tracking number.', 'code' => 'not_manual'], 409);
        }

        return $this->one($shipments->setManualTracking($shipment, trim($data['carrier_name']), trim($data['tracking_number']), $request->user()->id));
    }

    /** Phase B49 (Module 13 §55–56): the label — personal data, so permission-checked, audited, never cached. */
    public function label(Request $request, Shipment $shipment, ShippingLabelService $labels): Response
    {
        Gate::forUser($request->user())->authorize('manage', $shipment);
        if ($shipment->status === ShipmentStatus::Cancelled) {
            abort(404);
        }
        $bytes = $labels->pdf($shipment);
        $this->audit($request, 'shipping.label_downloaded', $shipment);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="label-'.($shipment->order->order_number ?? $shipment->public_id).'.pdf"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Phase B49 (Module 12 §64): the courier (or rider) paid over the cash it
     * collected. Records each shipment's cash on its order's payment, once.
     */
    public function codRemittance(Request $request, ShipmentService $shipments): JsonResponse
    {
        Gate::forUser($request->user())->authorize('recordCash', \App\Domain\Payments\Models\Payment::class);
        $data = $request->validate([
            'shipment_ids' => ['required', 'array', 'min:1', 'max:200'],
            'shipment_ids.*' => ['string', 'size:26'],
            'reference' => ['required', 'string', 'max:100'],
        ]);
        $selected = Shipment::query()->whereIn('public_id', $data['shipment_ids'])->where('cod_status', 'to_remit')->get();
        $count = $shipments->recordCodRemittance($selected, trim($data['reference']), $request->user()->id);

        return response()->json(['data' => ['recorded' => $count, 'amount_minor' => (int) $selected->sum('cod_amount_minor')]]);
    }

    private function one(Shipment $shipment): JsonResponse
    {
        return (new ShipmentResource($shipment->load(['items.orderItem', 'trackingEvents', 'order'])))->response()->setStatusCode($shipment->wasRecentlyCreated ? 201 : 200);
    }

    private function audit(Request $request, string $action, Shipment $shipment): void
    {
        app(\App\Domain\Compliance\Services\AuditLogger::class)->record($action, ['shipment' => $shipment->public_id, 'tracking_number' => $shipment->tracking_number], $shipment, $shipment->store_id, $request->user());
    }

    public function trackingEvents(Request $request, Shipment $shipment): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('view', $shipment);

        return ShipmentTrackingEventResource::collection(
            $shipment->trackingEvents()->orderByDesc('occurred_at')->get()
        );
    }
}
