<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', 'alpha_dash'],
            'address' => ['nullable', 'string'],
            'contact' => ['nullable', 'string', 'max:255'],
            'fulfillment_priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
