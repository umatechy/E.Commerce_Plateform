<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\DeveloperPlatform\Models\ApiRequestLog;
use App\Domain\DeveloperPlatform\Support\ApiKeyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 31 §21 "API Request Logging" — Non-Negotiable: metadata
 * ONLY. Never logs the request/response body, the Authorization
 * header, or any query/form field value — only method, path, status,
 * and duration. Runs AFTER EnsureApiKeyAuthenticated (so ApiKeyContext
 * is already set) and after the response is produced (so the real
 * status code is known).
 */
final class LogApiRequest
{
    public function __construct(private readonly ApiKeyContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        $response = $next($request);

        if ($this->context->isSet()) {
            $key = $this->context->get();

            ApiRequestLog::query()->create([
                'store_id' => $key->store_id,
                'api_key_id' => $key->id,
                'method' => $request->method(),
                'endpoint' => $request->path(),
                'status_code' => $response->getStatusCode(),
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);
        }

        return $response;
    }
}
