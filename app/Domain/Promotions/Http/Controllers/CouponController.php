<?php

declare(strict_types=1);

namespace App\Domain\Promotions\Http\Controllers;

use App\Domain\Promotions\Http\Requests\SaveCouponRequest;
use App\Domain\Promotions\Http\Resources\CouponResource;
use App\Domain\Promotions\Models\Coupon;
use App\Domain\Promotions\Models\Promotion;
use App\Domain\Promotions\Policies\PromotionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Staff-facing Coupon API (Module 14 §23-26). Authorization is via
 * PromotionPolicy directly (a coupon always belongs to a Promotion —
 * managing coupons requires the same `promotions.manage` permission
 * as managing the promotion itself, not a separate permission key).
 */
final class CouponController
{
    public function index(Request $request, Promotion $promotion): AnonymousResourceCollection
    {
        $this->authorizeView($request, $promotion);

        return CouponResource::collection($promotion->coupons()->get());
    }

    public function store(SaveCouponRequest $request): JsonResponse
    {
        $promotion = Promotion::query()->findOrFail($request->input('promotion_id'));
        $this->authorizeManage($request, $promotion);

        $coupon = Coupon::query()->create([
            'promotion_id' => $promotion->id,
            'code' => $request->string('code')->toString(),
            'code_normalized' => Coupon::normalize($request->string('code')->toString()),
            'is_active' => $request->boolean('is_active', true),
            'usage_limit' => $request->input('usage_limit'),
            'customer_usage_limit' => $request->input('customer_usage_limit'),
        ]);

        return (new CouponResource($coupon->load('promotion')))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Coupon $coupon): \Illuminate\Http\Response
    {
        $this->authorizeManage($request, $coupon->promotion);
        $coupon->update(['is_active' => false]); // soft-disable, never hard-delete a coupon that may already have usage history

        return response()->noContent();
    }

    private function authorizeView(Request $request, Promotion $promotion): void
    {
        abort_unless(app(PromotionPolicy::class)->view($request->user(), $promotion), 404);
    }

    private function authorizeManage(Request $request, Promotion $promotion): void
    {
        abort_unless(app(PromotionPolicy::class)->manage($request->user(), $promotion), 403);
    }
}
