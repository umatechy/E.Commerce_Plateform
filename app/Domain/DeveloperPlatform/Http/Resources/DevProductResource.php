<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Module 31 §27/§65 — deliberately minimal, allowlisted fields only; never the full internal Product model. */
final class DevProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'price_minor' => $this->price_minor,
            'currency' => $this->currency,
            'status' => $this->status,
        ];
    }
}
