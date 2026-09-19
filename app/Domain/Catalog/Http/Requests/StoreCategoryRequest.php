<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // parent_id existence is checked here; SAME-TENANT and
            // no-cycle validation happens in CategoryController (Module
            // 07 §8) because it needs the resolved TenantContext, which
            // a FormRequest rule closure can also reach via app() but is
            // kept in the controller for symmetry with every other
            // cross-record validation in this codebase.
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,active,hidden,scheduled,archived'],
            'visibility' => ['nullable', 'in:public,navigation_only,search_only,hidden,private,scheduled'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
