<?php

declare(strict_types=1);

namespace App\Domain\Theme\Http\Controllers;

use App\Domain\Theme\Exceptions\InvalidThemeConfigException;
use App\Domain\Theme\Exceptions\StoreThemePublicationNotFoundException;
use App\Domain\Theme\Exceptions\ThemeNotEntitledException;
use App\Domain\Theme\Models\Theme;
use App\Domain\Theme\Services\ThemeEntitlements;
use App\Domain\Theme\Support\ThemeCatalog;
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
            $updated = $themes->updateDraft($this->currentStoreTheme(), $request->input('config'), $request->input('custom_css'), $request->user()->id);
        } catch (InvalidThemeConfigException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_theme_config'], 422);
        } catch (ThemeNotEntitledException $e) {
            return $this->notEntitled($e);
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

    public function publish(Request $request, ThemeService $themes): JsonResponse
    {
        abort_unless(app(ThemePolicy::class)->publish($request->user()), 403);

        try {
            return (new StoreThemeResource($themes->publish($this->currentStoreTheme(), $request->user()->id)))->response();
        } catch (ThemeNotEntitledException $e) {
            return $this->notEntitled($e);
        }
    }

    /**
     * Module 17 §18, §50 "Theme Library" (Phase B36): every active theme,
     * with whether the store's package includes it.
     */
    public function library(Request $request, ThemeEntitlements $entitlements): JsonResponse
    {
        abort_unless(app(ThemePolicy::class)->view($request->user()), 403);
        $current = $this->currentStoreTheme();

        return response()->json(['data' => Theme::query()->where('status', 'active')->orderBy('sort_order')->orderBy('id')->get()
            ->map(function (Theme $theme) use ($entitlements, $current) {
                $definition = ThemeCatalog::get($theme->key);

                return [
                    'key' => $theme->key, 'name' => $theme->name, 'description' => $theme->description, 'tier' => $theme->tier, 'version' => $theme->version,
                    'included' => $entitlements->allows(ThemeCatalog::TIER_FEATURE[$theme->tier] ?? null),
                    'published' => $current->theme_id === $theme->id,
                    'in_draft' => ($current->draft_config['theme'] ?? $current->theme->key) === $theme->key,
                    // What the card shows: the theme's own colours, fonts and layout.
                    'tokens' => $definition['tokens'], 'layout' => $definition['layout'], 'motion' => $definition['motion'],
                ];
            })->values()]);
    }

    /** Module 17 §18 (Phase B36): the draft takes the chosen theme; publishing makes it live. */
    public function select(Request $request, ThemeService $themes): JsonResponse
    {
        abort_unless(app(ThemePolicy::class)->manage($request->user()), 403);
        $data = $request->validate(['theme' => ['required', 'string', 'max:64']]);

        try {
            return (new StoreThemeResource($themes->selectTheme($this->currentStoreTheme(), $data['theme'], $request->user()->id)))->response();
        } catch (InvalidThemeConfigException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_theme_config'], 422);
        } catch (ThemeNotEntitledException $e) {
            return $this->notEntitled($e);
        }
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
        } catch (ThemeNotEntitledException $e) {
            return $this->notEntitled($e);
        }

        return (new StoreThemeResource($updated))->response();
    }

    private function notEntitled(ThemeNotEntitledException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'code' => 'feature_not_entitled', 'violations' => $e->violations], 403);
    }

    private function currentStoreTheme(): StoreTheme
    {
        return StoreTheme::query()->where('store_id', app(TenantContext::class)->storeId())->firstOrFail();
    }
}
