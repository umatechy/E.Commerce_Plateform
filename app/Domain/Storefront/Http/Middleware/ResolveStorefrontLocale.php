<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Http\Middleware;

use App\Domain\Storefront\Services\StorefrontLocale;
use App\Domain\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase B38: the storefront's own API calls outside the /storefront prefix
 * (cart, checkout, customer account) carry the page's language in
 * `X-Storefront-Locale`. Once the tenant is known, that language is used for
 * the answer — validation messages and translated names — if the store
 * offers it. Requests without the header (staff, integrations) are left
 * alone.
 */
final class ResolveStorefrontLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasHeader('X-Storefront-Locale') && app(TenantContext::class)->hasStore()) {
            app(StorefrontLocale::class)->resolve($request, '');
        }

        return $next($request);
    }
}
