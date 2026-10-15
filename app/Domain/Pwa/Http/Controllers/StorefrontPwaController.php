<?php

declare(strict_types=1);

namespace App\Domain\Pwa\Http\Controllers;

use App\Domain\Pwa\Services\PwaConfig;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Settings\Services\Locales;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Phase B55 (gap G16; Module 25 §8–10, §16, §72) — the store's app files,
 * served at the store's own address (/shop/{slug}/… or its domain root):
 *
 * - manifest.webmanifest: generated for the resolved store only (§7, §9);
 * - sw.js: the service worker, scoped to the store's address; a retired
 *   worker (app off, shop not open) clears its caches and unregisters (§83);
 * - offline: the branded page shown offline (§72), plain HTML with no script.
 */
final class StorefrontPwaController
{
    /** Pages about one person: never kept for offline use (Module 25 §14). */
    private const PRIVATE_PATHS = ['/account', '/checkout', '/cart', '/support', '/returns'];

    public function __construct(private readonly PwaConfig $pwa) {}

    public function manifest(Request $request): Response
    {
        $store = $this->store($request);
        if (! $this->pwa->available($store)) {
            throw new NotFoundHttpException();
        }
        $locale = (string) app(ConfigService::class)->get('store.default_locale');
        $manifest = $this->pwa->manifest($store, $this->base($request), Locales::isSupported($locale) ? $locale : Locales::DEFAULT);

        return response(json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function worker(Request $request): Response
    {
        $store = $this->store($request);
        $base = $this->base($request);
        $config = [
            'store' => $store->public_id,
            'version' => $this->pwa->version(),
            'base' => $base,
            'offline' => "{$base}/offline",
            'media' => "/storage/stores/{$store->public_id}/",
            'private' => self::PRIVATE_PATHS,
            'pageLimit' => (int) config('pwa.page_cache_entries'),
            'imageLimit' => (int) config('pwa.image_cache_entries'),
            'retired' => ! $this->pwa->available($store),
        ];
        $script = str_replace('__CONFIG__', json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR), (string) file_get_contents(resource_path('pwa/sw.js')));

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            // Browsers check for a new worker on every visit; it must never come from a cache.
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    public function offline(Request $request): Response
    {
        $store = $this->store($request);
        $base = $this->base($request);
        $locale = (string) app(ConfigService::class)->get('store.default_locale');
        $locale = Locales::isSupported($locale) ? $locale : Locales::DEFAULT;
        $colors = $this->pwa->colors($store);

        return response(view('pwa.offline', [
            'name' => $this->pwa->name($store),
            'home' => "{$base}/",
            'locale' => $locale,
            'dir' => Locales::direction($locale),
            'theme' => $colors['theme'],
            'background' => $colors['background'],
            'icon' => $this->pwa->icons($store)[192] ?? null,
            'urdu' => $locale === 'ur',
        ])->render(), 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache', 'X-Robots-Tag' => 'noindex']);
    }

    private function store(Request $request): Store
    {
        return $request->attributes->get('storefront.store');
    }

    private function base(Request $request): string
    {
        return (string) $request->attributes->get('storefront.base_path');
    }
}
