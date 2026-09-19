<?php

declare(strict_types=1);

namespace App\Domain\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, // internal id acceptable here: first-party admin UI only, not a public identifier (ADR-003 — public_id reserved for externally shared references)
            'name' => $this->name,
            'slug' => $this->slug,
            'is_system' => $this->is_system,
            'permissions' => $this->whenLoaded(
                'permissions',
                fn () => $this->permissions->pluck('key')
            ),
        ];
    }
}
