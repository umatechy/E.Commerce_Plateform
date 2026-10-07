<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Module 06 §25: "Cost price must never be exposed to customers. Access
 * must be restricted through permissions." cost_price_minor is included
 * ONLY when Gate::authorize('viewCostPrice', ...) passes for the
 * CURRENT authenticated user — not merely omitted by convention, but
 * conditionally built via Gate::forUser()->allows(), so a caller
 * without the products.view_cost permission (or Owner) never receives
 * the field in the JSON payload at all, not just a null-masked one.
 *
 * @mixin \App\Domain\Catalog\Models\Product
 */
final class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canViewCost = $request->user()
            && Gate::forUser($request->user())->allows('viewCostPrice', $this->resource);

        return [
            'id' => $this->public_id,
            // The numeric key the staff write APIs take (inventory, promotion
            // targets, admin orders). Staff-only resource.
            'internal_id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'status' => $this->status->value,
            'visibility' => $this->visibility->value,
            'brand' => $this->whenLoaded('brand', fn () => ['id' => $this->brand->public_id, 'name' => $this->brand->name]),
            'brand_id' => $this->brand_id,
            'tax_class_id' => $this->tax_class_id, // Phase B46
            'primary_category_id' => $this->primary_category_id,
            'category_ids' => $this->whenLoaded('categories', fn () => $this->categories->pluck('id')->values()),
            // Phase B39 (Module 06 §35–37).
            'is_featured' => (bool) $this->is_featured,
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->pluck('name')->values()),
            'collection_ids' => $this->whenLoaded('collections', fn () => $this->collections->pluck('public_id')->values()),
            // Phase B43 (Module 06 §36, §93).
            'sort_priority' => (int) $this->sort_priority,
            'badge_ids' => $this->whenLoaded('badges', fn () => $this->badges->pluck('id')->values()),
            'price_minor' => $this->price_minor,
            'sale_price_minor' => $this->sale_price_minor,
            'effective_price_minor' => $this->effectivePriceMinor(),
            'currency' => $this->currency,
            $this->mergeWhen($canViewCost, [
                'cost_price_minor' => $this->cost_price_minor,
            ]),
            'published_at' => $this->published_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
        ];
    }
}
