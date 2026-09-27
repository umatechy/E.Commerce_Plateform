<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Module 31 §66 "Customer Data API" — Non-Negotiable: NEVER password/security metadata; only documented, minimal fields. */
final class DevCustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
