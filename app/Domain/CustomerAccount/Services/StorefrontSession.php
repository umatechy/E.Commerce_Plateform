<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccount\Services;

use App\Domain\Orders\Models\Customer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Phase B25 — how a shopper stays signed in on the web storefront.
 *
 * The storefront keeps the customer's Sanctum token in an HttpOnly,
 * SameSite=Lax cookie instead of JavaScript-readable storage, so script
 * injected into a page could not read or steal it. The cookie is only
 * honoured on requests that carry the `X-Storefront-Request: 1` header
 * (UseStorefrontCustomerSession): another site cannot add that header to
 * a request without passing CORS, so a cookie-bearing cross-site request
 * is never authenticated (CSRF). Headless and mobile clients keep using
 * the bearer token directly.
 *
 * On the platform host several stores share one origin (/shop/{slug}),
 * so the cookie name carries the store slug; on a custom domain it is a
 * host-only cookie of its own.
 */
final class StorefrontSession
{
    public const HEADER = 'X-Storefront-Request';

    private const LIFETIME_DAYS = 30;

    public static function cookieName(?string $storeSlug): string
    {
        $slug = $storeSlug !== null ? (string) preg_replace('/[^a-z0-9-]/', '', strtolower($storeSlug)) : '';

        return $slug !== '' ? "sf_session_{$slug}" : 'sf_session';
    }

    /** A fresh, expiring token for a web session. */
    public function issue(Customer $customer): string
    {
        return $customer->createToken('storefront-session', ['*'], now()->addDays(self::LIFETIME_DAYS))->plainTextToken;
    }

    public function cookie(Request $request, string $token): Cookie
    {
        return cookie(
            self::cookieName($request->header('X-Store-Slug')),
            $token,
            self::LIFETIME_DAYS * 24 * 60,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax',
        );
    }

    public function forget(Request $request): Cookie
    {
        return cookie()->forget(self::cookieName($request->header('X-Store-Slug')), '/', null);
    }
}
