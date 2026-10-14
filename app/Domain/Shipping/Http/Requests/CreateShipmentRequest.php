<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // enforced via Gate::authorize('create', Shipment::class) in the controller
    }

    public function rules(): array
    {
        return [
            'order_id' => ['required', 'string'], // the Order's public_id (ULID) — never the internal integer id (this milestone's Step 19: "Do not expose internal IDs as authorization authority")
            'warehouse_id' => ['required', 'integer'],
            // Phase B49: "another courier" (booked outside) and the connected couriers of CourierRegistry.
            'carrier' => ['required', \Illuminate\Validation\Rule::in(['store_pickup', 'local_delivery', 'mock_courier', \App\Domain\Shipping\Couriers\CourierRegistry::MANUAL, ...array_keys(app(\App\Domain\Shipping\Couriers\CourierRegistry::class)->all())])],
            'carrier_name' => ['required_if:carrier,manual_courier', 'nullable', 'string', 'max:64'],
            'tracking_number' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-\/ ]+$/', \Illuminate\Validation\Rule::unique('shipments', 'tracking_number')->where('store_id', app(\App\Domain\Tenancy\Support\TenantContext::class)->storeId())],
            'weight_grams' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'pieces' => ['nullable', 'integer', 'min:1', 'max:99'],
            'shipping_method_id' => ['nullable', 'integer'],
            'pickup_location_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }
}
