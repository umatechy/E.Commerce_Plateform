<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * Deployment: which reverse proxies the app believes, and what it believes
 * from them (config security.trusted_proxies, from TRUSTED_PROXIES).
 *
 * Only X-Forwarded-For (the visitor's IP: rate limits, sign-in lockouts,
 * the audit trail) and X-Forwarded-Proto (HTTPS: HSTS, secure URLs) are
 * read. X-Forwarded-Host, -Port and -Prefix never are: the Host header
 * decides which store a request belongs to, so a header a visitor can add
 * must not change it. With nothing configured, no proxy is trusted.
 */
final class TrustedProxies
{
    public const HEADERS = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO;

    /** @param string $list comma-separated IP addresses or CIDR ranges */
    public static function apply(string $list): void
    {
        $proxies = array_values(array_filter(array_map('trim', explode(',', $list)), fn (string $proxy) => $proxy !== ''));
        if ($proxies === []) {
            return;
        }

        TrustProxies::at($proxies);
        TrustProxies::withHeaders(self::HEADERS);
    }
}
