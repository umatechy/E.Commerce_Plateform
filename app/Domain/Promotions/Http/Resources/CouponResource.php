<?php

declare(strict_types=1);

namespace App\Domain\Promotions\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Promotions\Models\Coupon */
final class CouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'promotion_id' => $this->promotion?->public_id,
            'code' => $this->code,
            'is_active' => $this->is_active,
            'usage_limit' => $this->usage_limit,
            'used_count' => $this->used_count,
            'customer_usage_limit' => $this->customer_usage_limit,
        ];
    }
}
