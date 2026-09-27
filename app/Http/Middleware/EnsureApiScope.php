<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\DeveloperPlatform\Models\ApiScope;
use App\Domain\DeveloperPlatform\Support\ApiKeyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Module 31 §15-16 "API Scopes / Scope + Policy Model" — checked AFTER EnsureApiKeyAuthenticated, never a substitute for it. */
final class EnsureApiScope
{
    public function __construct(private readonly ApiKeyContext $context) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $required = ApiScope::from($scope); // a typo in route middleware config fails loudly at registration/first-hit, never silently

        if (! $this->context->get()->hasScope($required)) {
            return response()->json(['message' => "This API key does not have the \"{$scope}\" scope.", 'code' => 'insufficient_scope'], 403);
        }

        return $next($request);
    }
}
