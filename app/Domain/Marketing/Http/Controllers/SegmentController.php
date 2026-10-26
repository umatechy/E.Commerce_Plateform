<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Http\Controllers;

use App\Domain\Marketing\Exceptions\InvalidSegmentRuleException;
use App\Domain\Marketing\Http\Requests\SaveSegmentRequest;
use App\Domain\Marketing\Http\Resources\SegmentResource;
use App\Domain\Marketing\Models\MarketingSegment;
use App\Domain\Marketing\Policies\MarketingPolicy;
use App\Domain\Marketing\Services\MarketingSegmentService;
use App\Domain\Orders\Http\Resources\CustomerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Staff-facing Segment API (Module 15 §10-11).
 *
 * Owner decision 2026-10-26: customer groups (segments) are Business and
 * Premium (`marketing.advanced`; Module 15 §92 "Advanced segmentation").
 */
final class SegmentController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeView($request);

        return SegmentResource::collection(MarketingSegment::query()->get());
    }

    public function store(SaveSegmentRequest $request, MarketingSegmentService $segments): JsonResponse
    {
        $this->authorizeManage($request);

        try {
            $segments->validateRules($request->input('rules'));
        } catch (InvalidSegmentRuleException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_segment_rule'], 422);
        }

        $segment = MarketingSegment::query()->create($request->only(['name', 'rules']));

        return (new SegmentResource($segment))->response()->setStatusCode(201);
    }

    /** Module 15 Step 5/§8 "Audience Preview" — read-only, never authoritative for the actual campaign run (execution re-resolves the audience itself). */
    public function preview(Request $request, MarketingSegment $segment, MarketingSegmentService $segments): AnonymousResourceCollection
    {
        $this->authorizeView($request);

        return CustomerResource::collection($segments->resolveAudience($segment));
    }

    private function authorizeView(Request $request): void
    {
        abort_unless(app(MarketingPolicy::class)->viewAny($request->user()), 403);
        self::assertIncluded();
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless(app(MarketingPolicy::class)->manage($request->user()), 403);
        self::assertIncluded();
    }

    /** The package must include customer groups (403 feature_not_entitled). */
    public static function assertIncluded(): void
    {
        if (! app(\App\Domain\Packages\Services\EntitlementService::class)->hasFeature('marketing.advanced')) {
            abort(response()->json(['message' => 'Customer groups are part of the Business and Premium packages.', 'code' => 'feature_not_entitled'], 403));
        }
    }
}
