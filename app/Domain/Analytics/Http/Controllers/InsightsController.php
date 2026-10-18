<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Http\Controllers;

use App\Domain\Analytics\Policies\AnalyticsPolicy;
use App\Domain\Analytics\Services\InsightsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** UI-23: GET /api/v1/insights — suggestions from the store's own figures (analytics.view). */
final class InsightsController
{
    public function __invoke(Request $request, InsightsService $insights): JsonResponse
    {
        abort_unless(app(AnalyticsPolicy::class)->view($request->user()), 403);

        return response()->json(['data' => $insights->all()]);
    }
}
