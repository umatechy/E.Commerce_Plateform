<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAttributeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'key' => ['required', 'string', 'max:64', 'alpha_dash'],
            'type' => ['required', 'in:select,multi_select,boolean,numeric,text,color'],
            'group' => ['sometimes', 'nullable', 'string', 'max:80'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'values' => ['array'],
            // A value is its text, or (Phase B41) {value, color_code?}; AttributeManager checks both.
            'values.*' => ['required'],
            'values.*.value' => ['sometimes', 'string', 'max:255'],
            'values.*.color_code' => ['sometimes', 'nullable', 'string', 'max:7'],
        ];
    }
}
