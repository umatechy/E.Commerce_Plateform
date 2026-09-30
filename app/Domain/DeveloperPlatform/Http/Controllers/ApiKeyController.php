<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Http\Controllers;

use App\Domain\DeveloperPlatform\Exceptions\ApplicationNotActiveException;
use App\Domain\DeveloperPlatform\Exceptions\InvalidApiScopeException;
use App\Domain\DeveloperPlatform\Http\Requests\IssueApiKeyRequest;
use App\Domain\DeveloperPlatform\Http\Resources\ApiKeyResource;
use App\Domain\DeveloperPlatform\Models\ApiKey;
use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use App\Domain\DeveloperPlatform\Policies\DeveloperPlatformPolicy;
use App\Domain\DeveloperPlatform\Services\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

/**
 * Module 31 §9-12/§40 "API Key Architecture / Application Credentials"
 * — the plaintext secret is returned EXACTLY ONCE, in the store()/
 * rotate() response body, never logged, never re-displayable.
 * `{application}` route-model-binding is already tenant-scoped
 * (DeveloperApplication uses BelongsToTenant) — a cross-tenant
 * application id 404s before this controller code even runs.
 */
final class ApiKeyController
{
    public function index(Request $request, DeveloperApplication $application): AnonymousResourceCollection
    {
        abort_unless(app(DeveloperPlatformPolicy::class)->view($request->user()), 403);

        return ApiKeyResource::collection($application->apiKeys()->get());
    }

    public function store(IssueApiKeyRequest $request, DeveloperApplication $application, ApiKeyService $apiKeys): JsonResponse
    {
        abort_unless(app(DeveloperPlatformPolicy::class)->manage($request->user()), 403);

        try {
            $issued = $apiKeys->issue($application, $request->input('scopes'), $request->date('expires_at'));
        } catch (ApplicationNotActiveException|InvalidApiScopeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_request'], 422);
        }

        app(\App\Domain\Compliance\Services\AuditLogger::class)->record('developer.api_key.issued', [
            'actor_user_id' => $request->user()->id, 'application_id' => $application->id, 'api_key_id' => $issued['key']->id,
        ]);

        return (new ApiKeyResource($issued['key']))
            ->additional(['secret' => $issued['plaintext']]) // shown exactly once, here — never again
            ->response()
            ->setStatusCode(201);
    }

    public function revoke(Request $request, DeveloperApplication $application, ApiKey $apiKey, ApiKeyService $apiKeys): JsonResponse
    {
        abort_unless(app(DeveloperPlatformPolicy::class)->manage($request->user()), 403);
        abort_unless($apiKey->developer_application_id === $application->id, 404);

        $apiKeys->revoke($apiKey);

        app(\App\Domain\Compliance\Services\AuditLogger::class)->record('developer.api_key.revoked', [
            'actor_user_id' => $request->user()->id, 'api_key_id' => $apiKey->id,
        ], $apiKey);

        return response()->json(status: 204);
    }

    public function rotate(Request $request, DeveloperApplication $application, ApiKey $apiKey, ApiKeyService $apiKeys): JsonResponse
    {
        abort_unless(app(DeveloperPlatformPolicy::class)->manage($request->user()), 403);
        abort_unless($apiKey->developer_application_id === $application->id, 404);

        $issued = $apiKeys->rotate($apiKey);

        app(\App\Domain\Compliance\Services\AuditLogger::class)->record('developer.api_key.rotated', [
            'actor_user_id' => $request->user()->id, 'old_api_key_id' => $apiKey->id, 'new_api_key_id' => $issued['key']->id,
        ], $apiKey);

        return (new ApiKeyResource($issued['key']))->additional(['secret' => $issued['plaintext']])->response()->setStatusCode(201);
    }
}
