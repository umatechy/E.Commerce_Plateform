<?php

declare(strict_types=1);

namespace App\Domain\Pwa\Services;

use App\Domain\Packages\Services\EntitlementService;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Settings\Services\Locales;
use App\Domain\Storefront\Models\StorefrontAvailability;
use App\Domain\Storefront\Services\StorefrontExperience;
use App\Domain\Storefront\Services\StorefrontGate;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Support\Facades\Storage;

/**
 * Phase B55 (gap G16; Module 25 §5–9, §25–28, §55–59, §76) — a store's
 * installable app, worked out from its settings, its published theme and
 * its package. Always for the store the request was resolved to (tenant
 * first, §7), and every address stays inside that store's own scope on
 * the address it was opened on (§9, §28): no cross-store or external
 * resources, no open redirects.
 *
 * The app is "available" only when the package includes it (pwa.enabled),
 * the store has it on, and the shop is open. Otherwise the service worker
 * retires itself and clears its caches (§83).
 */
final class PwaConfig
{
    /** Shortcut paths (§59): only pages every store has. */
    private const SHORTCUTS = [
        'shop' => ['/products', 'Shop', 'خریداری'],
        'cart' => ['/cart', 'Cart', 'کارٹ'],
        'orders' => ['/account/orders', 'Your orders', 'آپ کے آرڈرز'],
        'help' => ['/help', 'Help', 'مدد'],
    ];

    public function __construct(
        private readonly ConfigService $config,
        private readonly EntitlementService $entitlements,
        private readonly StorefrontGate $gate,
    ) {}

    public function included(): bool
    {
        return $this->entitlements->hasFeature('pwa.enabled');
    }

    /** The app is offered: in the package, turned on, and the shop open. */
    public function available(Store $store): bool
    {
        return $this->included() && (bool) $this->config->get('pwa.enabled') && $this->gate->availability($store) === StorefrontAvailability::Open;
    }

    public function pushConfigured(): bool
    {
        return trim((string) config('pwa.vapid.public_key')) !== '' && trim((string) config('pwa.vapid.private_key')) !== '';
    }

    public function pushOffered(Store $store): bool
    {
        return $this->available($store) && $this->pushConfigured() && (bool) $this->config->get('pwa.push');
    }

    /**
     * What the storefront page needs (the `storefront.pwa` prop).
     *
     * @return array<string, mixed>
     */
    public function forPage(Store $store, string $base): array
    {
        $available = $this->available($store);

        return [
            'enabled' => $available,
            'manifest_url' => "{$base}/manifest.webmanifest",
            'worker_url' => "{$base}/sw.js",
            'scope' => "{$base}/",
            // The start of this store's cache names (sw.js): what the page may clear, and nothing of another store.
            'cache_prefix' => "ut-{$store->public_id}-",
            'theme_color' => $this->colors($store)['theme'],
            'icon' => $available ? ($this->icons($store)[192] ?? null) : null,
            'install_prompt' => $available && $this->installable($store) && (bool) $this->config->get('pwa.install_prompt'),
            'mobile_nav' => $available ? (string) $this->config->get('pwa.mobile_nav') : 'off',
            'push_key' => $this->pushOffered($store) ? (string) config('pwa.vapid.public_key') : null,
        ];
    }

    /** Browsers offer installation only with 192 and 512 pixel icons (§57). */
    public function installable(Store $store): bool
    {
        $icons = $this->icons($store);

        return isset($icons[192], $icons[512]);
    }

    /**
     * The web app manifest (§8), localised to the store's default language (§41).
     *
     * @return array<string, mixed>
     */
    public function manifest(Store $store, string $base, string $locale): array
    {
        $name = $this->name($store);
        $colors = $this->colors($store);
        $icons = [];
        foreach ($this->icons($store) as $size => $url) {
            $icons[] = ['src' => $url, 'sizes' => "{$size}x{$size}", 'type' => 'image/png', 'purpose' => 'any'];
        }
        $urdu = $locale === 'ur';

        return [
            'id' => "{$base}/",
            'name' => $name,
            'short_name' => $this->shortName($name),
            'description' => $this->clean((string) ($this->shell($store)['store']['tagline'] ?? '')) ?: $name,
            'lang' => $locale,
            'dir' => Locales::direction($locale),
            'start_url' => "{$base}/?source=app",
            'scope' => "{$base}/",
            'display' => (string) $this->config->get('pwa.display'),
            'orientation' => 'any',
            'theme_color' => $colors['theme'],
            'background_color' => $colors['background'],
            'icons' => $icons,
            'shortcuts' => array_values(array_map(fn (array $s) => ['name' => $urdu ? $s[2] : $s[1], 'url' => $base.$s[0]], self::SHORTCUTS)),
            'categories' => ['shopping'],
        ];
    }

    /**
     * The version in every cache name (§25): the store's app settings and
     * the deployed assets. A change to either starts fresh caches.
     */
    public function version(): string
    {
        $build = public_path('build/manifest.json');
        $assets = is_file($build) ? substr((string) md5_file($build), 0, 10) : 'dev';

        return $assets.'-'.(int) $this->config->get('pwa.version');
    }

    public function name(Store $store): string
    {
        $name = $this->clean((string) $this->config->get('pwa.name'));

        return $name !== '' ? mb_substr($name, 0, 45) : mb_substr($store->name, 0, 45);
    }

    public function shortName(string $name): string
    {
        $short = $this->clean((string) $this->config->get('pwa.short_name'));

        return mb_substr($short !== '' ? $short : $name, 0, 12);
    }

    /** @return array{theme: string, background: string} */
    public function colors(Store $store): array
    {
        $tokens = $this->shell($store)['theme']['tokens'] ?? [];
        $pick = fn (mixed $own, mixed $theme, string $fallback): string => self::isColor($own) ? strtoupper((string) $own) : (self::isColor($theme) ? strtoupper((string) $theme) : $fallback);

        return [
            'theme' => $pick($this->config->get('pwa.theme_color'), $tokens['primary'] ?? null, '#111827'),
            'background' => $pick($this->config->get('pwa.background_color'), $tokens['background'] ?? null, '#FFFFFF'),
        ];
    }

    /**
     * The icon in each size, as site paths of this store's own files.
     *
     * @return array<int, string>
     */
    public function icons(Store $store): array
    {
        $source = (string) $this->config->get('pwa.icon');
        if ($source === '' || ! app(PwaIcons::class)->belongsTo($store, $source)) {
            return [];
        }
        $icons = [];
        foreach (PwaIcons::SIZES as $size) {
            $path = PwaIcons::variantPath($source, $size);
            if (Storage::disk((string) config('storefront.images.disk'))->exists(substr($path, strlen('/storage/')))) {
                $icons[$size] = $path;
            }
        }

        return $icons;
    }

    public static function isColor(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1;
    }

    /** Text for the manifest: no markup, no control characters. */
    private function clean(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F<>]+/u', ' ', strip_tags($text)));
    }

    /** @return array<string, mixed> */
    private function shell(Store $store): array
    {
        return app(StorefrontExperience::class)->shell($store);
    }
}
