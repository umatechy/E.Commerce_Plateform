<?php

declare(strict_types=1);

namespace App\Domain\Theme\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Theme\Models\StoreTheme */
final class StoreThemeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'theme' => $this->theme->key,
            'draft_config' => $this->draft_config,
            'published_config' => $this->published_config,
            'custom_css' => $this->custom_css,
            'is_published' => $this->isPublished(),
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
