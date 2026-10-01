<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Http\Controllers;

use App\Domain\Marketing\Exceptions\InvalidCampaignStateTransitionException;
use App\Domain\Marketing\Http\Requests\ActivateCampaignRequest;
use App\Domain\Marketing\Http\Requests\SaveCampaignRequest;
use App\Domain\Marketing\Http\Resources\CampaignRecipientResource;
use App\Domain\Marketing\Http\Resources\CampaignResource;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Policies\MarketingPolicy;
use App\Domain\Marketing\Services\CampaignService;
use App\Domain\Settings\Services\StoreClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Staff-facing Campaign API (Module 15 §5-9/§79). Every method:
 * authenticated (staff.principal, via route group), authorized
 * (MarketingPolicy, direct calls — same precedent as
 * ShippingConfigPolicy/PromotionPolicy where a Gate::policy()
 * registration would be more boilerplate than benefit).
 */
final class CampaignController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeView($request);

        return CampaignResource::collection(Campaign::query()->withCount('recipients')->orderByDesc('created_at')->paginate(25));
    }

    public function show(Request $request, Campaign $campaign): CampaignResource
    {
        $this->authorizeView($request, $campaign);

        return new CampaignResource($campaign->loadCount('recipients'));
    }

    public function store(SaveCampaignRequest $request): JsonResponse
    {
        $this->authorizeManage($request);

        $campaign = Campaign::query()->create($request->validated());

        return (new CampaignResource($campaign))->response()->setStatusCode(201);
    }

    /** Module 15 §7/Step 6 — Draft/Scheduled -> Active (immediately or at a future scheduled_at). */
    public function activate(ActivateCampaignRequest $request, Campaign $campaign, CampaignService $campaigns, StoreClock $clock): JsonResponse
    {
        $this->authorizeManage($request, $campaign);

        try {
            // A time typed without an offset is wall-clock time in the store's timezone (Module 33 §50).
            $scheduledAt = $request->filled('scheduled_at') ? $clock->parse((string) $request->input('scheduled_at')) : null;
            $updated = $campaigns->activate($campaign, $scheduledAt);
        } catch (InvalidCampaignStateTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_transition'], 422);
        }

        return (new CampaignResource($updated))->response();
    }

    public function pause(Request $request, Campaign $campaign, CampaignService $campaigns): JsonResponse
    {
        $this->authorizeManage($request, $campaign);

        try {
            $updated = $campaigns->pause($campaign);
        } catch (InvalidCampaignStateTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_transition'], 422);
        }

        return (new CampaignResource($updated))->response();
    }

    public function resume(Request $request, Campaign $campaign, CampaignService $campaigns): JsonResponse
    {
        $this->authorizeManage($request, $campaign);

        try {
            $updated = $campaigns->resume($campaign);
        } catch (InvalidCampaignStateTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_transition'], 422);
        }

        return (new CampaignResource($updated))->response();
    }

    public function cancel(Request $request, Campaign $campaign, CampaignService $campaigns): JsonResponse
    {
        $this->authorizeManage($request, $campaign);

        try {
            $updated = $campaigns->cancel($campaign);
        } catch (InvalidCampaignStateTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_transition'], 422);
        }

        return (new CampaignResource($updated))->response();
    }

    public function recipients(Request $request, Campaign $campaign): AnonymousResourceCollection
    {
        $this->authorizeView($request, $campaign);

        return CampaignRecipientResource::collection($campaign->recipients()->with('customer')->paginate(50));
    }

    private function authorizeView(Request $request, ?Campaign $campaign = null): void
    {
        $policy = app(MarketingPolicy::class);
        $allowed = $campaign !== null ? $policy->view($request->user(), $campaign) : $policy->viewAny($request->user());
        abort_unless($allowed, $campaign !== null ? 404 : 403);
    }

    private function authorizeManage(Request $request, ?Campaign $campaign = null): void
    {
        abort_unless(app(MarketingPolicy::class)->manage($request->user(), $campaign), 403);
    }
}
