<?php

declare(strict_types=1);

namespace App\Domain\Theme\Http\Controllers;

use App\Domain\Theme\Services\ThemeResolver;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Public, unauthenticated resolved-theme endpoint — mirrors B13's
 * SeoPublicController / B14's public domain-facing pattern exactly.
 * Tenant is resolved from the explicit {storeSlug} PATH segment, never
 * a header (consistent with every other public storefront-data
 * endpoint since B13). Only the PUBLISHED configuration is ever
 * returned here — never the draft (Module 17 §10, Non-Negotiable:
 * "a preview must not accidentally become production... do not expose
 * private store configuration publicly").
 */
final class ThemePublicController
{
    public function show(string $storeSlug, ThemeResolver $resolver): JsonResponse
    {
        $store = Store::query()->where('slug', $storeSlug)->firstOrFail();
        app(TenantContext::class)->resolveToStore($store->id);

        $resolved = $resolver->resolvePublished($store);

        if ($resolved === null) {
            return response()->json(['message' => 'No published theme configuration exists for this store.'], 404);
        }

        return response()->json(['data' => $resolved]);
    }
}
