<?php

declare(strict_types=1);

namespace App\Domain\Cart\Http\Resources;

use App\Domain\Catalog\Models\ProductStatus;
use App\Domain\Catalog\Models\ProductVisibility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 11 §67 "Wishlist Availability" — always reports LIVE status,
 * never assumes a saved product remains purchasable forever.
 *
 * @mixin \App\Domain\Cart\Models\WishlistItem
 */
final class WishlistItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $product = $this->product;
        $variant = $this->variant;

        $available = $product !== null
            && $product->status === ProductStatus::Active
            && $product->visibility === ProductVisibility::Public
            && ($variant === null || $variant->status === 'active');

        return [
            'id' => $this->id,
            'product_id' => $product?->public_id,
            'product_name' => $product?->name,
            'current_price_minor' => $available
                ? ($variant !== null ? $variant->effectivePriceMinor() : $product->effectivePriceMinor())
                : null,
            'availability' => $product === null ? 'unavailable' : ($available ? 'available' : 'unavailable'),
            'added_at' => $this->created_at->toIso8601String(),
        ];
    }
}
