<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\DeveloperPlatform\Services\ApiKeyService;
use App\Domain\DeveloperPlatform\Support\ApiKeyContext;
use App\Domain\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 31 §4.4/Non-Negotiable — the developer API's ONLY
 * authentication mechanism: a hash-verified API key, deliberately
 * NEVER Sanctum session/token auth (a Developer Application is not a
 * User) and NEVER OAuth/JWT (ADR-002 — see
 * docs/development/b18-inspection-findings.md "Critical Conflict").
 * On success, establishes BOTH ApiKeyContext and TenantContext for
 * the remainder of the request — every downstream developer-API
 * controller/policy relies on these, never re-deriving tenant
 * identity from any request parameter.
 */
final class EnsureApiKeyAuthenticated
{
    public function __construct(
        private readonly ApiKeyService $apiKeys,
        private readonly ApiKeyContext $context,
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            return response()->json(['message' => 'Missing or malformed Authorization header.', 'code' => 'unauthenticated'], 401);
        }

        $key = $this->apiKeys->verify(substr($header, 7));

        if ($key === null) {
            // Module 31 §45 "API Key Enumeration" — one generic message
            // for every failure mode (unknown prefix, bad secret,
            // revoked, expired) — never distinguishes which.
            return response()->json(['message' => 'Invalid or inactive API credentials.', 'code' => 'unauthenticated'], 401);
        }

        // Phase B52 (Module 30 §61): the platform-wide kill switch for the developer API.
        if (app(\App\Domain\Settings\Services\ConfigService::class)->get('platform.developer_api_enabled') === false) {
            return response()->json(['message' => 'The developer API is switched off for now. Try again later.', 'code' => 'developer_api_disabled'], 503)->withHeaders(['Retry-After' => '600']);
        }

        // Phase B51 (Module 31 §2.27): a suspended, closed or archived store's API keys stop working with it.
        $store = \App\Domain\Tenancy\Models\Store::query()->withTrashed()->find($key->store_id);
        if ($store === null || ! $store->isOperational()) {
            return response()->json(['message' => 'This store is not open for API access.', 'code' => 'store_not_operational'], 423);
        }

        $this->context->set($key);
        $this->tenantContext->resolveToStore($key->store_id);

        return $next($request);
    }
}
