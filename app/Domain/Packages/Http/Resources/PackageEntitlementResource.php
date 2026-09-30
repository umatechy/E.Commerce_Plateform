<?php

declare(strict_types=1);

namespace App\Domain\Packages\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Packages\Models\PackageEntitlement */
final class PackageEntitlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'enforcement' => $this->enforcement?->value,
            'period' => $this->period?->value,
            'enabled' => $this->boolean_value,
            'limit' => $this->is_unlimited ? null : $this->limit_value,
            'unlimited' => $this->is_unlimited,
        ];
    }
}
