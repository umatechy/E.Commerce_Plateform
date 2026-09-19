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
            'type' => ['required', 'in:select,multi_select,boolean,numeric,text'],
            'values' => ['array'],
            'values.*' => ['string', 'max:255'],
        ];
    }
}
