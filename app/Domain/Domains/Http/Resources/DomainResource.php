<?php

declare(strict_types=1);

namespace App\Domain\Domains\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * verification_token is $hidden on the model — never returned here, even to the owning store's own staff, beyond what verificationInstructions() explicitly surfaces during initiation (Module 19 §55 "never return provider secrets/verification secrets unnecessarily").
 *
 * @mixin \App\Domain\Domains\Models\Domain
 */
final class DomainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'hostname' => $this->hostname,
            'domain_type' => $this->domain_type->value,
            'status' => $this->status->value,
            'is_primary' => $this->is_primary,
            'ssl_status' => $this->ssl_status->value,
            'verified_at' => $this->verified_at?->toIso8601String(),
        ];
    }
}
