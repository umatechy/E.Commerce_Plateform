<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveShippingMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:flat_rate,free,weight_based,price_based,store_pickup,local_delivery'],
            'is_active' => ['sometimes', 'boolean'],
            'free_shipping_threshold_minor' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
