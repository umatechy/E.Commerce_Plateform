<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Http\Controllers;

use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Http\Requests\ReserveStockRequest;
use App\Domain\Inventory\Http\Resources\StockReservationResource;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Module 08 §20-22 "Stock Reservation". This is the FOUNDATION future
 * Cart/Checkout (Phase B7) and Orders (Phase B5) will call —
 * reference_type/reference_id let a future caller tag a reservation as
 * "cart:123" or "order:456" without a schema change. No cart/order
 * code is built here (this milestone's explicit scope boundary).
 */
final class ReservationController
{
    public function store(ReserveStockRequest $request, Inventory $inventory, InventoryService $service): JsonResponse
    {
        Gate::forUser($request->user())->authorize('adjust', $inventory);

        try {
            $reservation = $service->reserve(
                $inventory,
                quantity: (int) $request->input('quantity'),
                idempotencyKey: $request->string('idempotency_key'),
                ttlMinutes: (int) $request->input('ttl_minutes', 15),
            );
        } catch (InsufficientStockException $e) {
            return response()->json([
                'message' => 'Not enough available stock to reserve.',
                'code' => 'insufficient_stock',
            ], 422);
        }

        return (new StockReservationResource($reservation))->response()->setStatusCode(201);
    }

    public function release(Request $request, StockReservation $reservation, InventoryService $service): JsonResponse
    {
        $inventory = Inventory::query()->findOrFail($reservation->inventory_id);
        Gate::forUser($request->user())->authorize('adjust', $inventory);

        $service->release($reservation);

        return response()->json(status: 204);
    }
}
