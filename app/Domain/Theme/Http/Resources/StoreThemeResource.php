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
            // Phase B38: the storefront languages besides the default, whose texts can be translated here.
            'translation_languages' => $this->translationLanguages(),
        ];
    }

    /** @return list<array<string, string>> */
    private function translationLanguages(): array
    {
        $config = app(\App\Domain\Settings\Services\ConfigService::class);
        $default = (string) ($config->get('store.default_locale') ?? \App\Domain\Settings\Services\Locales::DEFAULT);

        return \App\Domain\Settings\Services\Locales::describe(array_values(array_diff((array) $config->get('store.languages'), [$default])));
    }
}
