<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Catalog\Models\Category */
final class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'status' => $this->status->value,
            'visibility' => $this->visibility,
            'sort_order' => $this->sort_order,
            'default_sort' => $this->default_sort, // Phase B43 (Module 07 §17)
        ];
    }
}
