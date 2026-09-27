<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Http\Controllers;

use App\Domain\DeveloperPlatform\Exceptions\InvalidWebhookUrlException;
use App\Domain\DeveloperPlatform\Http\Requests\CreateWebhookSubscriptionRequest;
use App\Domain\DeveloperPlatform\Http\Resources\WebhookSubscriptionResource;
use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use App\Domain\DeveloperPlatform\Models\WebhookSubscription;
use App\Domain\DeveloperPlatform\Policies\DeveloperPlatformPolicy;
use App\Domain\DeveloperPlatform\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class WebhookSubscriptionController
{
    public function index(Request $request, DeveloperApplication $application): AnonymousResourceCollection
    {
        abort_unless(app(DeveloperPlatformPolicy::class)->view($request->user()), 403);

        return WebhookSubscriptionResource::collection($application->webhookSubscriptions()->get());
    }

    public function store(CreateWebhookSubscriptionRequest $request, DeveloperApplication $application, WebhookService $webhooks): JsonResponse
    {
        abort_unless(app(DeveloperPlatformPolicy::class)->manage($request->user()), 403);

        try {
            $result = $webhooks->subscribe($application, $request->input('url'), $request->input('events'));
        } catch (InvalidWebhookUrlException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_webhook_url'], 422);
        }

        return (new WebhookSubscriptionResource($result['subscription']))
            ->additional(['signing_secret' => $result['plaintextSecret']]) // shown exactly once, here — never again
            ->response()
            ->setStatusCode(201);
    }

    public function disable(Request $request, DeveloperApplication $application, WebhookSubscription $webhookSubscription, WebhookService $webhooks): JsonResponse
    {
        abort_unless(app(DeveloperPlatformPolicy::class)->manage($request->user()), 403);
        abort_unless($webhookSubscription->developer_application_id === $application->id, 404);

        $webhooks->disable($webhookSubscription);

        return response()->json(status: 204);
    }
}
