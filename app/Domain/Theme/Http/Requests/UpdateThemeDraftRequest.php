<?php

declare(strict_types=1);

namespace App\Domain\Theme\Http\Requests;

use App\Domain\Theme\Policies\ThemePolicy;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateThemeDraftRequest extends FormRequest
{
    /**
     * Permission is checked here so it runs BEFORE validation: a user
     * without theme access gets 403, never a 422 that describes the
     * payload rules. StoreThemeController keeps its own check as well.
     */
    public function authorize(): bool
    {
        return app(ThemePolicy::class)->manage($this->user());
    }

    public function rules(): array
    {
        return [
            // `present`, not `required`: an empty configuration object is
            // a valid draft (Laravel treats an empty array as "missing").
            'config' => ['present', 'array'],
            'custom_css' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ];
    }
}
