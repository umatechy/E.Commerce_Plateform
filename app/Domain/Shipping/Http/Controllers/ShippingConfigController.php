<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Http\Controllers;

use App\Domain\Shipping\Http\Requests\SaveShippingMethodRequest;
use App\Domain\Shipping\Http\Requests\SaveShippingRateRequest;
use App\Domain\Shipping\Http\Requests\SaveShippingZoneRequest;
use App\Domain\Shipping\Http\Resources\ShippingMethodResource;
use App\Domain\Shipping\Http\Resources\ShippingRateResource;
use App\Domain\Shipping\Http\Resources\ShippingZoneResource;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Shipping\Models\ShippingRate;
use App\Domain\Shipping\Models\ShippingZone;
use App\Domain\Shipping\Policies\ShippingConfigPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Module 13 §5/§22 "Shipping Configuration" — staff CRUD for zones/
 * methods/rates. Authorization uses ShippingConfigPolicy directly
 * (no Gate::policy() registration — no single model backs shipping
 * configuration; see that class's docblock).
 */
final class ShippingConfigController
{
    public function zones(Request $request): AnonymousResourceCollection
    {
        $this->authorizeManage($request);

        return ShippingZoneResource::collection(ShippingZone::query()->get());
    }

    public function storeZone(SaveShippingZoneRequest $request): JsonResponse
    {
        $this->authorizeManage($request);

        $zone = ShippingZone::query()->create($request->validated());

        return (new ShippingZoneResource($zone))->response()->setStatusCode(201);
    }

    public function methods(Request $request): AnonymousResourceCollection
    {
        $this->authorizeManage($request);

        return ShippingMethodResource::collection(ShippingMethod::query()->get());
    }

    public function storeMethod(SaveShippingMethodRequest $request): JsonResponse
    {
        $this->authorizeManage($request);

        $method = ShippingMethod::query()->create($request->validated());

        return (new ShippingMethodResource($method))->response()->setStatusCode(201);
    }

    /** Phase B31 (G6): the rates the store has set, so the admin page can show them. */
    public function rates(Request $request): AnonymousResourceCollection
    {
        $this->authorizeManage($request);

        return ShippingRateResource::collection(ShippingRate::query()->orderBy('shipping_zone_id')->orderBy('shipping_method_id')->get());
    }

    public function storeRate(SaveShippingRateRequest $request): JsonResponse
    {
        $this->authorizeManage($request);

        // Tenant-scoped existence check on both references (mirrors
        // Phase B3/B5's identical cross-tenant relation-validation
        // pattern) — a cross-tenant zone/method id simply is not found.
        ShippingZone::query()->findOrFail($request->input('shipping_zone_id'));
        ShippingMethod::query()->findOrFail($request->input('shipping_method_id'));

        $rate = ShippingRate::query()->updateOrCreate(
            ['shipping_zone_id' => $request->input('shipping_zone_id'), 'shipping_method_id' => $request->input('shipping_method_id')],
            $request->safe()->only(['currency', 'base_cost_minor', 'per_unit_cost_minor', 'unit_threshold']),
        );

        return (new ShippingRateResource($rate))->response()->setStatusCode(201);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless(app(ShippingConfigPolicy::class)->manage($request->user()), 403);
    }
}
