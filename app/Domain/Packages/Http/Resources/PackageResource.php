<?php

declare(strict_types=1);

namespace App\Domain\Packages\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public-safe (this is served on /api/v1/public/packages for
 * pre-signup/marketing pages) — deliberately excludes nothing sensitive
 * because packages have nothing sensitive: no pricing/billing fields
 * exist on this model yet (Module 29 Billing owns pricing; Module 04
 * explicitly separates "exact commercial prices ... defined separately
 * from the technical entitlement model" — this resource reflects that
 * separation rather than inventing a price field).
 */
final class PackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'entitlements' => PackageEntitlementResource::collection($this->whenLoaded('entitlements')),
        ];
    }
}
