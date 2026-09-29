<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Http\Controllers;

use App\Domain\Orders\Models\Order;
use App\Domain\Shipping\Exceptions\FulfillmentNotAllowedException;
use App\Domain\Shipping\Exceptions\InvalidShipmentStateTransitionException;
use App\Domain\Shipping\Exceptions\ShipmentQuantityExceedsOrderedException;
use App\Domain\Shipping\Http\Requests\CreateShipmentRequest;
use App\Domain\Shipping\Http\Requests\UpdateShipmentStatusRequest;
use App\Domain\Shipping\Http\Resources\ShipmentResource;
use App\Domain\Shipping\Http\Resources\ShipmentTrackingEventResource;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\Models\ShipmentStatus;
use App\Domain\Shipping\Services\ShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

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

        return ShipmentResource::collection(Shipment::query()->with('items')->orderByDesc('created_at')->paginate(25));
    }

    public function show(Request $request, Shipment $shipment): ShipmentResource
    {
        Gate::forUser($request->user())->authorize('view', $shipment);

        return new ShipmentResource($shipment->load(['items', 'trackingEvents']));
    }

    public function store(CreateShipmentRequest $request, ShipmentService $shipments): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', Shipment::class);

        // Tenant-scoped lookup by public_id (never the internal id) —
        // BelongsToTenant's global scope makes a cross-tenant order_id
        // simply not found (404), mirroring every other cross-tenant
        // reference check in this codebase since Phase B3.
        $order = Order::query()->where('public_id', $request->string('order_id')->toString())->firstOrFail();

        try {
            $shipment = $shipments->createShipment(
                $order,
                warehouseId: (int) $request->input('warehouse_id'),
                carrier: $request->string('carrier')->toString(),
                items: $request->input('items'),
                shippingMethodId: $request->input('shipping_method_id'),
                pickupLocationId: $request->input('pickup_location_id'),
                idempotencyKey: $request->string('idempotency_key')->toString(),
            );
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

    public function trackingEvents(Request $request, Shipment $shipment): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('view', $shipment);

        return ShipmentTrackingEventResource::collection(
            $shipment->trackingEvents()->orderByDesc('occurred_at')->get()
        );
    }
}
