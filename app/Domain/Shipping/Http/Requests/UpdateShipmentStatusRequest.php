<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateShipmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:ready,picked_up,in_transit,out_for_delivery,delivered,delivery_failed,returning,returned,cancelled'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
