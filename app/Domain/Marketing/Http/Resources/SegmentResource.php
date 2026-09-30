<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Marketing\Models\MarketingSegment */
final class SegmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'rules' => $this->rules,
        ];
    }
}
