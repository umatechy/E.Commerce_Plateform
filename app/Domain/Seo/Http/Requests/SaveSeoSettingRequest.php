<?php

declare(strict_types=1);

namespace App\Domain\Seo\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveSeoSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'seoable_type' => ['required', 'in:store,product,category,brand,content_page'],
            'seoable_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'canonical_override' => ['nullable', 'url', 'max:2048'],
            'og_title' => ['nullable', 'string', 'max:255'],
            'og_description' => ['nullable', 'string', 'max:320'],
            'og_image_url' => ['nullable', 'url', 'max:2048'],
            'robots_index' => ['sometimes', 'in:index,noindex'],
            'robots_follow' => ['sometimes', 'in:follow,nofollow'],
        ];
    }
}
