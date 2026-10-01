<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Http\Controllers;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Exceptions\DuplicateOpeningStockException;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Http\Requests\AdjustStockRequest;
use App\Domain\Inventory\Http\Requests\OpeningStockRequest;
use App\Domain\Inventory\Http\Requests\StoreInventoryRequest;
use App\Domain\Inventory\Http\Resources\InventoryResource;
use App\Domain\Inventory\Http\Resources\StockMovementResource;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Module 08 §75 "API Design". Every method: authenticated (route
 * middleware), authorized (Gate::authorize below), tenant-scoped
 * (BelongsToTenant on Inventory + explicit cross-tenant re-checks on
 * product_id/product_variant_id/warehouse_id, mirroring
 * ProductController::assertRelationsBelongToTenant() from Phase B3),
 * validated (FormRequests), idempotent where the operation mutates
 * balance (InventoryService).
 */
final class InventoryController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('viewAny', Inventory::class);

        // Phase B31 (G6): the admin list names the product and filters by stock state.
        $filters = $request->validate(['stock' => ['nullable', 'in:low,out']]);

        return InventoryResource::collection(
            Inventory::query()->with(['warehouse', 'product', 'variant.product'])
                ->when(($filters['stock'] ?? null) === 'low', fn ($q) => $q->whereNotNull('reorder_point')->whereRaw('(on_hand - reserved) <= reorder_point'))
                ->when(($filters['stock'] ?? null) === 'out', fn ($q) => $q->whereRaw('(on_hand - reserved) <= 0'))
                ->orderByDesc('id')
                ->paginate(25)
        );
    }

    public function show(Request $request, Inventory $inventory): InventoryResource
    {
        Gate::forUser($request->user())->authorize('view', $inventory);

        return new InventoryResource($inventory->load(['warehouse', 'product', 'variant.product']));
    }

    /**
     * Creates the inventory RECORD (on_hand=0) for a product/variant in
     * a warehouse — Module 08 §8. Does NOT set any stock quantity;
     * setOpeningStock() (below) is the one documented stock-in path for
     * a freshly created record (Module 08 §32).
     */
    public function store(StoreInventoryRequest $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', Inventory::class);

        $this->assertCatalogRelationsBelongToTenant($request);

        if ($request->filled('warehouse_id') && Warehouse::query()->find($request->input('warehouse_id')) === null) {
            throw ValidationException::withMessages(['warehouse_id' => 'The selected warehouse does not exist in this store.']);
        }

        $existing = Inventory::query()
            ->where('warehouse_id', $request->input('warehouse_id'))
            ->where('product_id', $request->input('product_id'))
            ->where('product_variant_id', $request->input('product_variant_id'))
            ->first();

        if ($existing !== null) {
            // Application-layer duplicate guard — see the inventories
            // migration's docblock on why the DB unique constraint alone
            // cannot catch this for simple products (NULL variant_id).
            return (new InventoryResource($existing))->response()->setStatusCode(200);
        }

        $inventory = Inventory::query()->create($request->validated());

        return (new InventoryResource($inventory))->response()->setStatusCode(201);
    }

    public function adjust(AdjustStockRequest $request, Inventory $inventory, InventoryService $service): JsonResponse
    {
        Gate::forUser($request->user())->authorize('adjust', $inventory);

        try {
            $movement = $service->adjustStock(
                $inventory,
                delta: (int) $request->input('quantity'),
                reason: $request->string('reason')->toString(),
                actorId: $request->user()->id,
                idempotencyKey: $request->input('idempotency_key') ?? (string) Str::uuid(),
            );
        } catch (InsufficientStockException $e) {
            return response()->json([
                'message' => 'This adjustment would take on-hand stock below zero.',
                'code' => 'insufficient_stock',
            ], 422);
        }

        return (new StockMovementResource($movement))->response()->setStatusCode(201);
    }

    public function openingStock(OpeningStockRequest $request, Inventory $inventory, InventoryService $service): JsonResponse
    {
        Gate::forUser($request->user())->authorize('adjust', $inventory);

        try {
            $movement = $service->setOpeningStock(
                $inventory,
                quantity: (int) $request->input('quantity'),
                reason: $request->string('reason')->toString(),
                actorId: $request->user()->id,
                idempotencyKey: $request->input('idempotency_key') ?? (string) Str::uuid(),
            );
        } catch (DuplicateOpeningStockException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'opening_stock_already_set',
            ], 422);
        }

        return (new StockMovementResource($movement))->response()->setStatusCode(201);
    }

    public function movements(Request $request, Inventory $inventory): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('view', $inventory);

        return StockMovementResource::collection(
            $inventory->movements()->orderByDesc('created_at')->paginate(25)
        );
    }

    /**
     * Module 08 §3/§73: a cross-tenant product/variant reference must be
     * rejected — mirrors ProductController::assertRelationsBelongToTenant()
     * (Phase B3), using the same tenant-scoped find() pattern (Product/
     * ProductVariant both use BelongsToTenant).
     */
    private function assertCatalogRelationsBelongToTenant(Request $request): void
    {
        if ($request->filled('product_id') && Product::query()->find($request->input('product_id')) === null) {
            throw ValidationException::withMessages(['product_id' => 'The selected product does not exist in this store.']);
        }

        if ($request->filled('product_variant_id') && ProductVariant::query()->find($request->input('product_variant_id')) === null) {
            throw ValidationException::withMessages(['product_variant_id' => 'The selected variant does not exist in this store.']);
        }
    }
}
