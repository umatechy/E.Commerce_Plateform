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
        $variant = $this->variant;
        // Rows written before Phase B25 stored a variant without its product.
        $product = $this->product ?? $variant?->product;

        $available = $product !== null
            && $product->status === ProductStatus::Active
            && $product->visibility === ProductVisibility::Public
            && ($variant === null || $variant->status === 'active');

        return [
            'id' => $this->id,
            'product_id' => $product?->public_id,
            'product_name' => $product?->name,
            // Phase B25: what a storefront wishlist page shows.
            'product_slug' => $product?->slug,
            'variant_id' => $variant?->public_id,
            'variant_options' => $variant?->option_values,
            'image_url' => $product?->images->first()?->url(),
            'current_price_minor' => $available
                ? ($variant !== null ? $variant->effectivePriceMinor() : $product->effectivePriceMinor())
                : null,
            'availability' => $product === null ? 'unavailable' : ($available ? 'available' : 'unavailable'),
            'added_at' => $this->created_at->toIso8601String(),
        ];
    }
}
