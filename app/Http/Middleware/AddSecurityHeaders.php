<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 32 (Phase B22) — baseline HTTP security headers on every
 * response. A header a controller already set deliberately is kept.
 *
 * JSON API responses additionally get a deny-all Content-Security-Policy:
 * they are never meant to be rendered as documents. The Inertia admin
 * pages get no CSP here — Vite's dev server injects inline scripts, so a
 * page CSP needs nonce support and is a separate change.
 */
final class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        if ($request->is('api/*')) {
            $defaults['Content-Security-Policy'] = "default-src 'none'; frame-ancestors 'none'";
        }

        // HSTS only over HTTPS: sent on plain HTTP it is ignored anyway,
        // and on a proxy misconfiguration it would be misleading.
        if ($request->isSecure() && config('compliance.security_headers.hsts')) {
            $defaults['Strict-Transport-Security'] = 'max-age='.(int) config('compliance.security_headers.hsts_max_age').'; includeSubDomains';
        }

        foreach ($defaults as $name => $value) {
            if (! $headers->has($name)) {
                $headers->set($name, $value);
            }
        }

        return $response;
    }
}
