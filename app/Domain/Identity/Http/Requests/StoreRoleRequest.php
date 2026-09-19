<?php

declare(strict_types=1);

namespace App\Domain\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is enforced by RolePolicy via the controller's `$this->authorize()` call, not here.
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'permission_keys' => ['array'],
            'permission_keys.*' => ['string', 'exists:permissions,key'],
            // Deliberately NO 'store_id' rule: even if a client sends one,
            // it is never read — see RoleController, which relies solely
            // on TenantContext/BelongsToTenant for the owning store.
        ];
    }
}
