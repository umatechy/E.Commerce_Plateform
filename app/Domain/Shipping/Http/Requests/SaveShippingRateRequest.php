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

    /** Currency codes are compared upper-case (owner decision 2026-10-03). */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper(trim($this->input('currency')))]);
        }
    }

    public function rules(): array
    {
        return [
            'shipping_zone_id' => ['required', 'integer'],
            'shipping_method_id' => ['required', 'integer'],
            'currency' => ['required', 'string', 'size:3', \App\Domain\Settings\Services\Currencies::rule()],
            'base_cost_minor' => ['required', 'integer', 'min:0'],
            'per_unit_cost_minor' => ['nullable', 'integer', 'min:0'],
            'unit_threshold' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
