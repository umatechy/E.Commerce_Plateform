<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 31 §9 "API Key Response" — NEVER the secret; key_hash is also model-$hidden as defense-in-depth.
 *
 * @mixin \App\Domain\DeveloperPlatform\Models\ApiKey
 */
final class ApiKeyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'key_prefix' => $this->key_prefix,
            'scopes' => $this->scopes,
            'status' => $this->status->value,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
