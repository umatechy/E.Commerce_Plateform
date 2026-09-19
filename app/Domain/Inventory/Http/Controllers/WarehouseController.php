<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Http\Controllers;

use App\Domain\Inventory\Http\Requests\StoreWarehouseRequest;
use App\Domain\Inventory\Http\Resources\WarehouseResource;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Packages\Exceptions\FeatureNotEntitledException;
use App\Domain\Packages\Exceptions\SubscriptionInactiveException;
use App\Domain\Packages\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Module 08 §16 "Multi-Warehouse" / §71 "Package Entitlements": Basic
 * stores get exactly the one default warehouse StoreObserver already
 * created (Phase B4 addition); creating a SECOND warehouse requires the
 * 'inventory.multi_warehouse' feature (Business/Premium).
 */
final class WarehouseController
{
    private const FEATURE_KEY = 'inventory.multi_warehouse';

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('viewAny', Warehouse::class);

        return WarehouseResource::collection(Warehouse::query()->orderBy('fulfillment_priority')->get());
    }

    public function store(StoreWarehouseRequest $request, EntitlementService $entitlements): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', Warehouse::class);

        $existingCount = Warehouse::query()->count();

        if ($existingCount >= 1) {
            try {
                $entitlements->assertFeatureEntitled(self::FEATURE_KEY);
            } catch (FeatureNotEntitledException|SubscriptionInactiveException $e) {
                return response()->json([
                    'message' => 'Your current package supports a single warehouse. Upgrade to Business or Premium to add more.',
                    'code' => 'feature_not_entitled',
                ], 403);
            }
        }

        $warehouse = Warehouse::query()->create($request->validated());

        return (new WarehouseResource($warehouse))->response()->setStatusCode(201);
    }

    public function update(StoreWarehouseRequest $request, Warehouse $warehouse): WarehouseResource
    {
        Gate::forUser($request->user())->authorize('manage', $warehouse);

        $warehouse->update($request->validated());

        return new WarehouseResource($warehouse->refresh());
    }
}
