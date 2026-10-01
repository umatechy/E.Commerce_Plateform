<?php

declare(strict_types=1);

namespace App\Domain\Promotions\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Promotions\Models\Promotion */
final class PromotionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'internal_id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'target_scope' => $this->target_scope->value,
            'status' => $this->status->value,
            'percentage_value' => $this->percentage_value,
            'fixed_amount_minor' => $this->fixed_amount_minor,
            'currency' => $this->currency,
            'min_order_value_minor' => $this->min_order_value_minor,
            'max_discount_minor' => $this->max_discount_minor,
            'requires_coupon' => $this->requires_coupon,
            'priority' => $this->priority,
            'usage_limit' => $this->usage_limit,
            'used_count' => $this->used_count,
            'customer_usage_limit' => $this->customer_usage_limit,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'target_ids' => $this->whenLoaded('targets', fn () => $this->targets->pluck('target_id')),
        ];
    }
}
