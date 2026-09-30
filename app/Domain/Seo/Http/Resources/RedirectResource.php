<?php

declare(strict_types=1);

namespace App\Domain\Seo\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Seo\Models\Redirect */
final class RedirectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_path' => $this->source_path,
            'destination_path' => $this->destination_path,
            'status_code' => $this->status_code,
            'is_active' => $this->is_active,
            'reason' => $this->reason,
        ];
    }
}
