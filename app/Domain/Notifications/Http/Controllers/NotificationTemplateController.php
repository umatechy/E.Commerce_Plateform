<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Http\Controllers;

use App\Domain\Notifications\Exceptions\TemplateImmutableException;
use App\Domain\Notifications\Http\Requests\SaveTemplateRequest;
use App\Domain\Notifications\Http\Resources\NotificationTemplateResource;
use App\Domain\Notifications\Models\NotificationTemplate;
use App\Domain\Notifications\Policies\NotificationPolicy;
use App\Domain\Notifications\Services\NotificationTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Staff-facing Template API (Module 21 §12-14). */
final class NotificationTemplateController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeView($request);

        return NotificationTemplateResource::collection(NotificationTemplate::query()->get());
    }

    public function store(SaveTemplateRequest $request, NotificationTemplateService $templates): JsonResponse
    {
        $this->authorizeManage($request);

        $template = $templates->create($request->validated());

        return (new NotificationTemplateResource($template))->response()->setStatusCode(201);
    }

    public function update(SaveTemplateRequest $request, NotificationTemplate $template, NotificationTemplateService $templates): JsonResponse
    {
        $this->authorizeManage($request);

        try {
            $updated = $templates->update($template, $request->validated());
        } catch (TemplateImmutableException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'template_immutable'], 422);
        }

        return (new NotificationTemplateResource($updated))->response();
    }

    private function authorizeView(Request $request): void
    {
        abort_unless(app(NotificationPolicy::class)->view($request->user()), 403);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless(app(NotificationPolicy::class)->manage($request->user()), 403);
    }
}
