<?php

declare(strict_types=1);

namespace App\Domain\Cart\Http\Resources;

use App\Domain\Cart\Services\CartService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberately built from CartService::totals(), not a plain
 * $cart->toArray() — every field here reflects LIVE, server-recomputed
 * data (Module 11 §13 "Cart prices are provisional"), never a stale
 * stored value.
 *
 * @mixin \App\Domain\Cart\Models\Cart
 */
final class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $totals = app(CartService::class)->totals($this->resource);

        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'currency' => $totals['currency'],
            'items' => $totals['items'],
            'subtotal_minor' => $totals['subtotal_minor'],
            'has_issues' => $totals['has_issues'],
            'coupon_code' => $totals['coupon_code'],
            'promotion' => $totals['promotion'],
            'guest_token' => $this->when($this->isGuestCart(), $this->guest_token),
        ];
    }
}
