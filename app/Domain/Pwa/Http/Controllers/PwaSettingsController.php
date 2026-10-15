<?php

declare(strict_types=1);

namespace App\Domain\Pwa\Http\Controllers;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Domains\Models\Domain;
use App\Domain\Domains\Models\DomainStatus;
use App\Domain\Pwa\Models\PushSubscription;
use App\Domain\Pwa\Services\PwaConfig;
use App\Domain\Pwa\Services\PwaIcons;
use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Settings\Services\Locales;
use App\Domain\Storefront\Models\StorefrontAvailability;
use App\Domain\Storefront\Services\StorefrontGate;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use App\Domain\Theme\Policies\ThemePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Phase B55 (gap G16; Module 25 §7, §55–59, §76, §86) — Storefront →
 * Mobile app: the store's installable app. Reading needs theme.view,
 * changing theme.manage (the app is part of how the storefront looks).
 *
 * The page shows what the app looks like (the manifest the server will
 * serve), what still stands in the way of installing it, and how it is
 * used (installs, launches, offline views, push choices — counts only).
 */
final class PwaSettingsController
{
    public function __construct(
        private readonly PwaConfig $pwa,
        private readonly ConfigService $config,
    ) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless(app(ThemePolicy::class)->view($request->user()), 403);
        $store = $this->store();
        $base = '/shop/'.$store->slug;
        $locale = (string) $this->config->get('store.default_locale');
        $since = now()->subDays(30)->toDateString();

        return response()->json(['data' => [
            'can_manage' => app(ThemePolicy::class)->manage($request->user()),
            'included' => $this->pwa->included(),
            'settings' => [
                'enabled' => (bool) $this->config->get('pwa.enabled'),
                'name' => $this->config->get('pwa.name'),
                'short_name' => $this->config->get('pwa.short_name'),
                'theme_color' => $this->config->get('pwa.theme_color'),
                'background_color' => $this->config->get('pwa.background_color'),
                'display' => $this->config->get('pwa.display'),
                'install_prompt' => (bool) $this->config->get('pwa.install_prompt'),
                'mobile_nav' => $this->config->get('pwa.mobile_nav'),
                'push' => (bool) $this->config->get('pwa.push'),
            ],
            'icon' => $this->pwa->icons($store)[512] ?? null,
            'colors' => $this->pwa->colors($store),
            'manifest' => $this->pwa->manifest($store, $base, Locales::isSupported($locale) ? $locale : Locales::DEFAULT),
            'push_available' => $this->pwa->pushConfigured(),
            'checks' => $this->checks($store),
            'stats' => DB::table('pwa_daily_stats')->where('store_id', $store->id)->where('day', '>=', $since)
                ->selectRaw('event, sum(count) as total')->groupBy('event')->pluck('total', 'event')->map(fn ($n) => (int) $n),
            'push_devices' => PushSubscription::query()->where('status', 'active')->count(),
        ]]);
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless(app(ThemePolicy::class)->manage($request->user()), 403);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'name' => ['nullable', 'string', 'max:45', 'not_regex:/[<>]/'],
            'short_name' => ['nullable', 'string', 'max:12', 'not_regex:/[<>]/'],
            'theme_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'background_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'display' => ['required', Rule::in(['standalone', 'minimal-ui', 'browser'])],
            'install_prompt' => ['required', 'boolean'],
            'mobile_nav' => ['required', Rule::in(['installed', 'always', 'off'])],
            'push' => ['required', 'boolean'],
        ]);
        if ($data['push'] && ! $this->pwa->pushConfigured()) {
            return response()->json(['message' => 'Push notifications are not set up on the platform yet.', 'code' => 'push_not_configured'], 422);
        }
        $actor = $request->user()?->id;
        foreach ($data as $key => $value) {
            // An empty name or colour goes back to the default (the store's name, the theme's colours).
            $value === null || (is_string($value) && trim($value) === '')
                ? $this->config->clear("pwa.{$key}", SettingScope::Store, $actor)
                : $this->config->set("pwa.{$key}", $value, SettingScope::Store, $actor);
        }
        $this->bumpVersion($actor);
        $audit->record('pwa.settings_changed', ['enabled' => $data['enabled'], 'display' => $data['display'], 'push' => $data['push']]);

        return response()->json(['message' => 'Saved. Customers get the new app details the next time they open your store.']);
    }

    public function icon(Request $request, PwaIcons $icons, AuditLogger $audit): JsonResponse
    {
        abort_unless(app(ThemePolicy::class)->manage($request->user()), 403);
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png']]);
        $path = $icons->upload($this->store(), $request->file('file'), $request->user()?->id);
        $this->config->set('pwa.icon', $path, SettingScope::Store, $request->user()?->id);
        $this->bumpVersion($request->user()?->id);
        $audit->record('pwa.icon_changed', ['path' => $path]);

        return response()->json(['data' => ['icon' => PwaIcons::variantPath($path, 512)], 'message' => 'App icon saved.']);
    }

    /** A new version: browsers fetch the new manifest and start fresh caches (Module 25 §82). */
    private function bumpVersion(?int $actor): void
    {
        $this->config->set('pwa.version', ((int) $this->config->get('pwa.version')) + 1, SettingScope::Store, $actor);
    }

    /**
     * What stands between this store and an installable app (§86, §95).
     *
     * @return list<array{key: string, ok: bool, message: string}>
     */
    private function checks(Store $store): array
    {
        $https = str_starts_with((string) config('app.url'), 'https://')
            || Domain::query()->where('status', DomainStatus::Active->value)->where('ssl_status', 'active')->exists();

        return [
            ['key' => 'package', 'ok' => $this->pwa->included(), 'message' => 'Your package includes the app.'],
            ['key' => 'enabled', 'ok' => (bool) $this->config->get('pwa.enabled'), 'message' => 'The app is turned on.'],
            ['key' => 'open', 'ok' => app(StorefrontGate::class)->availability($store) === StorefrontAvailability::Open, 'message' => 'Your shop is open to customers.'],
            ['key' => 'icon', 'ok' => $this->pwa->installable($store), 'message' => 'An app icon (square, at least 512 × 512 pixels).'],
            ['key' => 'https', 'ok' => $https, 'message' => 'Your store is on a secure (https) address. Phones only install apps from secure addresses.'],
        ];
    }

    private function store(): Store
    {
        return Store::query()->findOrFail(app(TenantContext::class)->storeId());
    }
}
