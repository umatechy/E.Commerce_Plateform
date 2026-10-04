<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Catalog\Models\Attribute */
final class AttributeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'key' => $this->key,
            'type' => $this->type->value,
            'values' => $this->whenLoaded('values', fn () => $this->values->where('is_active', true)->pluck('value')->values()),
            // Phase B41: group, unit, active, and the values with their ids, colours and state.
            'group' => $this->group,
            'unit' => $this->unit,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
            'options' => $this->whenLoaded('values', fn () => $this->values->map(fn ($v) => [
                'id' => $v->id, 'value' => $v->value, 'slug' => $v->slug, 'color_code' => $v->color_code, 'is_active' => (bool) $v->is_active,
            ])->values()),
        ];
    }
}
