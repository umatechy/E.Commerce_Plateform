<?php

declare(strict_types=1);

namespace App\Domain\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never includes password/remember_token — Customer's own $hidden already excludes them, this is an explicit allow-list on top (same double-safety convention as UserResource, Phase B1).
 *
 * @mixin \App\Domain\Orders\Models\Customer
 */
final class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'email_verified' => $this->email_verified_at !== null,
        ];
    }
}
