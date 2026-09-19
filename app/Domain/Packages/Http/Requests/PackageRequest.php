<?php

declare(strict_types=1);

namespace App\Domain\Packages\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Enforced via Gate::authorize('manage', Package::class) in the controller.
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', 'alpha_dash'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }
}
