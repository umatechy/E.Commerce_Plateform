<?php

declare(strict_types=1);

namespace App\Domain\Packages\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Enforced via Gate::authorize('manage', Package::class) in the controller.
    }

    public function rules(): array
    {
        // Shared by create (POST) and update (PUT /packages/{package}). On
        // update every field is optional, so renaming a package does not
        // require resending its code; `code` stays unique either way (a
        // duplicate used to reach the database and fail with a 500).
        $package = $this->route('package');
        $presence = $package === null ? 'required' : 'sometimes';

        return [
            'code' => [$presence, 'string', 'max:32', 'alpha_dash', Rule::unique('packages', 'code')->ignore($package)],
            'name' => [$presence, 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }
}
