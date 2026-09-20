<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveSegmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'rules' => ['required', 'array', 'min:1'],
            'rules.*.field' => ['required', 'string'],
            'rules.*.operator' => ['required', 'string'],
            'rules.*.value' => ['required'],
        ];
    }
}
