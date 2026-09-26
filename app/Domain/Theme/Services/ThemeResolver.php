<?php

declare(strict_types=1);

namespace App\Domain\Theme\Services;

use App\Domain\Theme\Models\StoreTheme;
use App\Domain\Tenancy\Models\Store;

/**
 * Module 17 §8 (mirrors B13's SeoResolver / B14's
 * DomainResolverService exactly) — the ONE place a future rendering
 * layer would call to get a store's resolved theme configuration.
 * Never duplicated across controllers.
 */
final class ThemeResolver
{
    /** The LIVE, public-facing configuration — never the draft. */
    public function resolvePublished(Store $store): ?array
    {
        $storeTheme = StoreTheme::query()->where('store_id', $store->id)->first();

        if ($storeTheme === null || $storeTheme->published_config === null) {
            return null;
        }

        return [
            'config' => $storeTheme->published_config,
            'custom_css' => $storeTheme->custom_css,
            'published_at' => $storeTheme->published_at?->toIso8601String(),
        ];
    }

    /** Staff-only preview of the unpublished draft — never served on the public resolution path. */
    public function resolveDraft(StoreTheme $storeTheme): array
    {
        return ['config' => $storeTheme->draft_config, 'custom_css' => $storeTheme->custom_css];
    }
}
