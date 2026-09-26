<?php

declare(strict_types=1);

namespace App\Domain\Theme\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StoreThemePublicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'published_at' => $this->created_at->toIso8601String(),
        ];
    }
}
