<?php

declare(strict_types=1);

namespace App\Domain\Theme\Services;

use App\Domain\Theme\Models\StoreTheme;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;

/**
 * Module 17 §19 "Theme preview" (Phase B32): the storefront with the
 * DRAFT theme, before publishing.
 *
 * The preview token is, as §19 asks:
 * - short-lived: 30 minutes;
 * - tenant-scoped: it names its store and works only on that store;
 * - permission-protected: only staff who may view the theme get one
 *   (StoreThemeController), and it is encrypted and authenticated with
 *   the application key, so it cannot be forged or edited;
 * - non-indexable: preview pages are sent with `noindex, nofollow`.
 *
 * The preview never changes the storefront: customers keep the published
 * theme, and the cached pages are never built from a draft.
 *
 * Opening the link stores the token in a cookie limited to that store's
 * pages, so the person can browse the storefront in preview;
 * `?theme_preview=exit` ends it.
 */
final class ThemePreviewLink
{
    public const PARAMETER = 'theme_preview';
    private const COOKIE = 'theme_preview';
    private const TTL_MINUTES = 30;

    /** @return array{url: string, expires_at: string} */
    public function issue(Store $store, int $userId, string $storefrontPath): array
    {
        $expires = now()->addMinutes(self::TTL_MINUTES);
        $token = Crypt::encryptString((string) json_encode(['s' => $store->id, 'u' => $userId, 'e' => $expires->getTimestamp()]));

        return [
            'url' => rtrim($storefrontPath, '/').'/?'.http_build_query([self::PARAMETER => $token]),
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    /**
     * The draft theme to show for this request, or null for the published
     * one. Queues the cookie that keeps the preview while browsing.
     *
     * @return array{config: mixed, custom_css: ?string, expires_at: string}|null
     */
    public function draftFor(Request $request, Store $store, string $basePath): ?array
    {
        $path = $basePath === '' ? '/' : $basePath;
        $fromQuery = $request->query(self::PARAMETER);

        if ($fromQuery === 'exit') {
            Cookie::queue(Cookie::forget(self::COOKIE, $path));

            return null;
        }

        $token = is_string($fromQuery) && $fromQuery !== '' ? $fromQuery : $request->cookie(self::COOKIE);
        $claims = is_string($token) ? $this->claims($token) : null;

        if ($claims === null || $claims['s'] !== $store->id || $claims['e'] < now()->getTimestamp()) {
            return null;
        }

        $storeTheme = StoreTheme::query()->withoutTenantScope()->where('store_id', $store->id)->first();
        if ($storeTheme === null) {
            return null;
        }

        if (is_string($fromQuery) && $fromQuery !== '') {
            $minutes = (int) max(1, ceil(($claims['e'] - now()->getTimestamp()) / 60));
            Cookie::queue(Cookie::make(self::COOKIE, $fromQuery, $minutes, $path, null, $request->isSecure(), true, false, 'lax'));
        }

        return [
            'config' => $storeTheme->draft_config,
            'custom_css' => $storeTheme->custom_css,
            'expires_at' => now()->setTimestamp($claims['e'])->toIso8601String(),
        ];
    }

    /** @return array{s: int, u: int, e: int}|null */
    private function claims(string $token): ?array
    {
        try {
            $claims = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return null;
        }

        return is_array($claims) && is_int($claims['s'] ?? null) && is_int($claims['u'] ?? null) && is_int($claims['e'] ?? null)
            ? ['s' => $claims['s'], 'u' => $claims['u'], 'e' => $claims['e']]
            : null;
    }
}
