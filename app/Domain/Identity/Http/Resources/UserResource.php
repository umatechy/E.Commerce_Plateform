<?php

declare(strict_types=1);

namespace App\Domain\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API response boundary for User. Deliberately an explicit allow-list —
 * NEVER extends toArray() from the raw model (this prompt's "API
 * Resource Safety" section: never return raw models blindly). Password
 * hash, remember_token, and internal platform_role are never included
 * here by construction, not by hoping $hidden is remembered everywhere.
 *
 * @mixin \App\Domain\Identity\Models\User
 */
final class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'is_platform_staff' => $this->isPlatformStaff(),
        ];
    }
}
