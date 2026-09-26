<?php

declare(strict_types=1);

namespace App\Domain\Seo\Http\Controllers;

use App\Domain\Seo\Exceptions\InvalidRedirectException;
use App\Domain\Seo\Http\Requests\SaveRedirectRequest;
use App\Domain\Seo\Http\Resources\RedirectResource;
use App\Domain\Seo\Models\Redirect;
use App\Domain\Seo\Policies\SeoPolicy;
use App\Domain\Seo\Services\RedirectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Staff-facing Redirect API (Module 16 §11-13). */
final class RedirectController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless(app(SeoPolicy::class)->view($request->user()), 403);

        return RedirectResource::collection(Redirect::query()->orderByDesc('created_at')->paginate(25));
    }

    public function store(SaveRedirectRequest $request, RedirectService $redirects): JsonResponse
    {
        abort_unless(app(SeoPolicy::class)->manage($request->user()), 403);

        try {
            $redirect = $redirects->create($request->string('source_path'), $request->string('destination_path'), (int) $request->input('status_code', 301));
        } catch (InvalidRedirectException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_redirect'], 422);
        }

        return (new RedirectResource($redirect))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Redirect $redirect): \Illuminate\Http\Response
    {
        abort_unless(app(SeoPolicy::class)->manage($request->user()), 403);
        $redirect->update(['is_active' => false]);

        return response()->noContent();
    }
}
