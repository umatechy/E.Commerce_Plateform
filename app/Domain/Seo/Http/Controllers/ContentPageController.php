<?php

declare(strict_types=1);

namespace App\Domain\Seo\Http\Controllers;

use App\Domain\Seo\Exceptions\InvalidContentPageTransitionException;
use App\Domain\Seo\Http\Requests\SaveContentPageRequest;
use App\Domain\Seo\Http\Resources\ContentPageResource;
use App\Domain\Seo\Models\ContentPage;
use App\Domain\Seo\Models\ContentPageStatus;
use App\Domain\Seo\Policies\SeoPolicy;
use App\Domain\Seo\Services\ContentPageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Staff-facing Content Page API (Module 16 §19/§23-25). */
final class ContentPageController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless(app(SeoPolicy::class)->view($request->user()), 403);

        return ContentPageResource::collection(ContentPage::query()->orderByDesc('created_at')->paginate(25));
    }

    public function store(SaveContentPageRequest $request, ContentPageService $pages): JsonResponse
    {
        abort_unless(app(SeoPolicy::class)->manage($request->user()), 403);

        $page = $pages->create($request->validated());

        return (new ContentPageResource($page))->response()->setStatusCode(201);
    }

    public function update(SaveContentPageRequest $request, ContentPage $page, ContentPageService $pages): ContentPageResource
    {
        abort_unless(app(SeoPolicy::class)->manage($request->user()), 403);

        return new ContentPageResource($pages->update($page, $request->validated()));
    }

    public function transition(Request $request, ContentPage $page, ContentPageService $pages): JsonResponse
    {
        abort_unless(app(SeoPolicy::class)->manage($request->user()), 403);
        $request->validate(['status' => ['required', 'in:draft,scheduled,published,unpublished,archived']]);

        try {
            $updated = $pages->transitionTo($page, ContentPageStatus::from($request->string('status')->toString()));
        } catch (InvalidContentPageTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_transition'], 422);
        }

        return (new ContentPageResource($updated))->response();
    }
}
