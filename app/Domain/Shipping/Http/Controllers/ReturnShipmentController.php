<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Http\Controllers;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Returns\Models\ReturnStatus;
use App\Domain\Returns\Policies\ReturnPolicy;
use App\Domain\Shipping\Couriers\CourierRegistry;
use App\Domain\Shipping\Http\Resources\ShipmentResource;
use App\Domain\Shipping\Models\CourierAccount;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\Services\CourierShippingService;
use App\Domain\Shipping\Services\ShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Phase B49 (Module 13 §70; Module 09 §45–54 owns the return): the parcel a
 * customer sends back — a pickup booked with a connected courier, or a
 * label and tracking number for another courier or a drop-off. When the
 * courier collects it the return moves to "in transit" by itself; the
 * store still receives and inspects it.
 */
final class ReturnShipmentController
{
    public function index(Request $request, ReturnRequest $return): AnonymousResourceCollection
    {
        abort_unless(app(ReturnPolicy::class)->view($request->user()), 403);

        return ShipmentResource::collection(Shipment::query()->with(['trackingEvents', 'order'])->where('return_request_id', $return->id)->latest('id')->get());
    }

    public function store(Request $request, ReturnRequest $return, ShipmentService $shipments, CourierShippingService $couriers): JsonResponse
    {
        abort_unless(app(ReturnPolicy::class)->manage($request->user()), 403);
        $data = $request->validate([
            'carrier' => ['required', Rule::in([CourierRegistry::MANUAL, ...array_keys(app(CourierRegistry::class)->all())])],
            'warehouse_id' => ['nullable', 'integer'],
            'carrier_name' => ['required_if:carrier,manual_courier', 'nullable', 'string', 'max:64'],
            'tracking_number' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-\/ ]+$/', Rule::unique('shipments', 'tracking_number')->where('store_id', $return->store_id)],
            'weight_grams' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'pieces' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);
        if ($return->status !== ReturnStatus::Approved) {
            return response()->json(['message' => 'A return parcel is arranged once the return is approved and before it is on its way.', 'code' => 'return_not_approved'], 409);
        }
        if (Shipment::query()->where('return_request_id', $return->id)->where('status', '!=', 'cancelled')->exists()) {
            return response()->json(['message' => 'This return already has a parcel. Cancel it first to arrange another.', 'code' => 'return_shipment_exists'], 409);
        }
        $account = null;
        if (app(CourierRegistry::class)->find($data['carrier']) !== null) {
            $account = CourierAccount::query()->where('provider', $data['carrier'])->first();
            if ($account === null || ! $couriers->usable($account)) {
                return response()->json(['message' => 'This courier is not connected and switched on for your store (Shipping setup).', 'code' => 'courier_not_available'], 422);
            }
        }
        $warehouse = isset($data['warehouse_id'])
            ? Warehouse::query()->findOrFail($data['warehouse_id'])
            : Warehouse::query()->where('is_default', true)->firstOrFail();

        $shipment = $shipments->createReturnShipment($return, $warehouse->id, $data['carrier'], [
            'courier_account_id' => $account?->id, 'carrier_name' => $data['carrier_name'] ?? null, 'tracking_number' => $data['tracking_number'] ?? null,
            'weight_grams' => $data['weight_grams'] ?? null, 'pieces' => $data['pieces'] ?? null,
        ], $request->user()->id);
        if ($shipment->booking_status === 'booking') {
            $shipment = $couriers->book($shipment);
        }

        return (new ShipmentResource($shipment->load(['trackingEvents', 'order'])))->response()->setStatusCode(201);
    }
}
