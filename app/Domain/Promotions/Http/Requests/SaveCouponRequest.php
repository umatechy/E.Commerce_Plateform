<?php

declare(strict_types=1);

namespace App\Domain\Promotions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'promotion_id' => ['required', 'integer'],
            'code' => ['required', 'string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'customer_usage_limit' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
