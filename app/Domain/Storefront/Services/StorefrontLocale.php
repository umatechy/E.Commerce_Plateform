<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Services;

use App\Domain\Settings\Services\ConfigService;
use App\Domain\Settings\Services\Locales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;

/**
 * Phase B38 — SRS LOC-001/006, Module 05 §41: the language of one storefront
 * request.
 *
 * Order: `?lang=` in the address (pages; it is also what the language links
 * and hreflang alternates use, so each language has its own address), the
 * `X-Storefront-Locale` header (the storefront's own API calls), the
 * visitor's earlier choice (cookie), then the store's default language. Only
 * languages the store offers are ever used. Laravel's locale follows, so
 * validation messages come in the same language where a translation exists.
 *
 * Registered as a scoped service: one value per request.
 */
final class StorefrontLocale
{
    public const COOKIE = 'sf_lang';

    private string $current = Locales::DEFAULT;

    private string $default = Locales::DEFAULT;

    /** @var list<string> */
    private array $offered = [Locales::DEFAULT];

    public function __construct(private readonly ConfigService $config) {}

    public function resolve(Request $request, string $basePath): void
    {
        $default = (string) ($this->config->get('store.default_locale') ?? Locales::DEFAULT);
        $offered = array_values(array_filter((array) $this->config->get('store.languages'), fn ($code) => Locales::isSupported($code)));
        if (! Locales::isSupported($default)) {
            $default = Locales::DEFAULT;
        }
        if (! in_array($default, $offered, true)) {
            array_unshift($offered, $default);
        }

        $chosen = null;
        foreach ([$request->query('lang'), $request->header('X-Storefront-Locale'), $request->cookie(self::COOKIE)] as $candidate) {
            if (is_string($candidate) && in_array($candidate, $offered, true)) {
                $chosen = $candidate;
                break;
            }
        }
        // A language chosen in the address is remembered for this store's pages.
        $fromQuery = $request->query('lang');
        if (is_string($fromQuery) && in_array($fromQuery, $offered, true)) {
            Cookie::queue(Cookie::make(self::COOKIE, $fromQuery, 60 * 24 * 365, $basePath === '' ? '/' : $basePath, null, $request->isSecure(), false, false, 'lax'));
        }

        $this->default = $default;
        $this->offered = $offered;
        $this->current = $chosen ?? $default;
        App::setLocale($this->current);
    }

    public function current(): string
    {
        return $this->current;
    }

    public function default(): string
    {
        return $this->default;
    }

    public function isDefault(): bool
    {
        return $this->current === $this->default;
    }

    /** @return list<string> */
    public function offered(): array
    {
        return $this->offered;
    }

    public function direction(): string
    {
        return Locales::direction($this->current);
    }
}
