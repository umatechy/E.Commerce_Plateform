<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Billing\Models\PackagePrice */
final class PackagePriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'package' => $this->whenLoaded('package', fn () => ['code' => $this->package->code, 'name' => $this->package->name]),
            'billing_interval' => $this->billing_interval->value,
            'currency' => $this->currency,
            'amount_minor' => $this->amount_minor,
            'is_active' => $this->is_active,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
