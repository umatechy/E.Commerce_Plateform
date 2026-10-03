<?php

declare(strict_types=1);

namespace App\Domain\Theme\Http\Controllers;

use App\Domain\Theme\Exceptions\InvalidThemeConfigException;
use App\Domain\Theme\Exceptions\StoreThemePublicationNotFoundException;
use App\Domain\Theme\Http\Requests\UpdateThemeDraftRequest;
use App\Domain\Theme\Http\Resources\StoreThemePublicationResource;
use App\Domain\Theme\Http\Resources\StoreThemeResource;
use App\Domain\Theme\Models\StoreTheme;
use App\Domain\Theme\Policies\ThemePolicy;
use App\Domain\Theme\Services\ThemeService;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Staff-facing Theme API (Module 17 §40-42). Every mutating method:
 * authenticated (staff.principal, via route group), authorized
 * (ThemePolicy, direct calls — same precedent as every other multi-
 * method policy in this codebase), tenant-scoped implicitly (one
 * StoreTheme row per store, resolved via the current TenantContext,
 * never a client-supplied id).
 */
final class StoreThemeController
{
    public function show(Request $request): StoreThemeResource
    {
        abort_unless(app(ThemePolicy::class)->view($request->user()), 403);

        return new StoreThemeResource($this->currentStoreTheme());
    }

    public function updateDraft(UpdateThemeDraftRequest $request, ThemeService $themes, \App\Domain\Packages\Services\EntitlementService $entitlements): JsonResponse
    {
        abort_unless(app(ThemePolicy::class)->manage($request->user()), 403);

        // Module 17 §18/§46 "Custom CSS / Package Entitlement" — only
        // actually checked when the request is TRYING to set non-empty
        // custom CSS (a Basic-tier store can still edit its ordinary
        // design tokens/branding/sections without ever touching this
        // gate at all).
        if ($request->filled('custom_css')) {
            try {
                $entitlements->assertFeatureEntitled('theme.custom_css');
            } catch (\App\Domain\Packages\Exceptions\FeatureNotEntitledException|\App\Domain\Packages\Exceptions\SubscriptionInactiveException $e) {
                return response()->json(['message' => $e->getMessage(), 'code' => 'feature_not_entitled'], 403);
            }
        }

        try {
            $updated = $themes->updateDraft($this->currentStoreTheme(), $request->input('config'), $request->input('custom_css'));
        } catch (InvalidThemeConfigException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_theme_config'], 422);
        }

        return (new StoreThemeResource($updated))->response();
    }

    /**
     * Module 17 §19 (Phase B32): a 30-minute link to the storefront with the
     * draft theme. Only for staff who may view the theme.
     */
    public function previewLink(Request $request, \App\Domain\Theme\Services\ThemePreviewLink $links): JsonResponse
    {
        abort_unless(app(ThemePolicy::class)->view($request->user()), 403);
        $store = \App\Domain\Tenancy\Models\Store::query()->findOrFail(app(TenantContext::class)->storeId());

        $link = $links->issue($store, $request->user()->id, '/shop/'.$store->slug);
        app(\App\Domain\Compliance\Services\AuditLogger::class)->record('theme.preview_link_created', ['expires_at' => $link['expires_at']], $store);

        return response()->json(['data' => $link]);
    }

    public function publish(Request $request, ThemeService $themes): StoreThemeResource
    {
        abort_unless(app(ThemePolicy::class)->publish($request->user()), 403);

        return new StoreThemeResource($themes->publish($this->currentStoreTheme(), $request->user()->id));
    }

    public function publications(Request $request): AnonymousResourceCollection
    {
        abort_unless(app(ThemePolicy::class)->view($request->user()), 403);

        return StoreThemePublicationResource::collection($this->currentStoreTheme()->publications()->orderByDesc('created_at')->get());
    }

    public function rollback(Request $request, int $publicationId, ThemeService $themes): JsonResponse
    {
        abort_unless(app(ThemePolicy::class)->publish($request->user()), 403);

        try {
            $updated = $themes->rollbackTo($this->currentStoreTheme(), $publicationId, $request->user()->id);
        } catch (StoreThemePublicationNotFoundException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'publication_not_found'], 404);
        }

        return (new StoreThemeResource($updated))->response();
    }

    private function currentStoreTheme(): StoreTheme
    {
        return StoreTheme::query()->where('store_id', app(TenantContext::class)->storeId())->firstOrFail();
    }
}
