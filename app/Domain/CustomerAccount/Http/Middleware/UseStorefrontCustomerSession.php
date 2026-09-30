<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccount\Http\Middleware;

use App\Domain\CustomerAccount\Services\StorefrontSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase B25 — turns the storefront's HttpOnly session cookie into the
 * bearer token every customer API already understands, so no customer
 * endpoint needed a second authentication path. Only for requests that
 * carry X-Storefront-Request (see StorefrontSession for why), and never
 * over an explicit Authorization header.
 */
final class UseStorefrontCustomerSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->headers->get(StorefrontSession::HEADER) === '1' && $request->bearerToken() === null) {
            $token = $request->cookies->get(StorefrontSession::cookieName($request->header('X-Store-Slug')));

            if (is_string($token) && preg_match('/^\d+\|[A-Za-z0-9_\-]{20,}$/', $token) === 1) {
                $request->headers->set('Authorization', 'Bearer '.$token);
            }
        }

        return $next($request);
    }
}
