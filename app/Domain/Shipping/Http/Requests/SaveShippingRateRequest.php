<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveShippingRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shipping_zone_id' => ['required', 'integer'],
            'shipping_method_id' => ['required', 'integer'],
            'currency' => ['required', 'string', 'size:3'],
            'base_cost_minor' => ['required', 'integer', 'min:0'],
            'per_unit_cost_minor' => ['nullable', 'integer', 'min:0'],
            'unit_threshold' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
