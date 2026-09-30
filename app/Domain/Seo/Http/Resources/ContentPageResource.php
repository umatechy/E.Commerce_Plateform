<?php

declare(strict_types=1);

namespace App\Domain\Seo\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Seo\Models\ContentPage */
final class ContentPageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'body' => $this->body,
            'status' => $this->status->value,
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
