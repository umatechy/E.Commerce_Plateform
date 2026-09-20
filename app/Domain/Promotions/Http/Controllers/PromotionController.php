<?php

declare(strict_types=1);

namespace App\Domain\Promotions\Http\Controllers;

use App\Domain\Promotions\Http\Requests\SavePromotionRequest;
use App\Domain\Promotions\Http\Resources\PromotionResource;
use App\Domain\Promotions\Models\Promotion;
use App\Domain\Promotions\Models\PromotionTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Staff-facing Promotion API (Module 14 §80 "Promotion API" / admin
 * management). Every method: authenticated (staff.principal, via
 * route group), authorized (Gate::authorize below), tenant-scoped,
 * validated where mutating.
 */
final class PromotionController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('viewAny', Promotion::class);

        return PromotionResource::collection(Promotion::query()->with('targets')->orderByDesc('created_at')->paginate(25));
    }

    public function show(Request $request, Promotion $promotion): PromotionResource
    {
        Gate::forUser($request->user())->authorize('view', $promotion);

        return new PromotionResource($promotion->load('targets'));
    }

    public function store(SavePromotionRequest $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', Promotion::class);

        $targetScope = $request->input('target_scope');

        if ($targetScope !== 'order' && empty($request->input('target_ids'))) {
            return response()->json(['message' => 'At least one target must be selected for this scope.', 'code' => 'targets_required'], 422);
        }

        $promotion = DB::transaction(function () use ($request, $targetScope) {
            $promotion = Promotion::query()->create($request->safe()->except('target_ids'));

            foreach ($request->input('target_ids', []) as $targetId) {
                PromotionTarget::query()->create([
                    'promotion_id' => $promotion->id,
                    'target_type' => $targetScope,
                    'target_id' => $targetId,
                ]);
            }

            return $promotion;
        });

        return (new PromotionResource($promotion->load('targets')))->response()->setStatusCode(201);
    }

    public function update(SavePromotionRequest $request, Promotion $promotion): PromotionResource
    {
        Gate::forUser($request->user())->authorize('manage', $promotion);

        $targetScope = $request->input('target_scope', $promotion->target_scope->value);

        DB::transaction(function () use ($request, $promotion, $targetScope) {
            $promotion->update($request->safe()->except('target_ids'));

            if ($request->has('target_ids')) {
                $promotion->targets()->delete();
                foreach ($request->input('target_ids', []) as $targetId) {
                    PromotionTarget::query()->create([
                        'promotion_id' => $promotion->id,
                        'target_type' => $targetScope,
                        'target_id' => $targetId,
                    ]);
                }
            }
        });

        return new PromotionResource($promotion->fresh('targets'));
    }
}
