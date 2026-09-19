<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ChangeStorePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Enforced via 'can:super-admin.impersonate'-style Gate check at the route level.
    }

    public function rules(): array
    {
        return [
            'package_code' => ['required', 'string', 'exists:packages,code'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
