<?php

declare(strict_types=1);

namespace App\Domain\Packages\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 04 §40 "Customer Package Visibility" — Store Owners can view
 * current package, status, trial/renewal dates without contacting
 * Umar Techy. Usage/limits are served separately by UsageOverviewResource
 * (kept apart so a lightweight subscription check doesn't always pay
 * the cost of computing every metric's usage).
 *
 * @mixin \App\Domain\Packages\Models\Subscription
 */
final class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status->value,
            'grants_access' => $this->status->grantsAccess(),
            'package' => new PackageResource($this->whenLoaded('package')),
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'grace_period_ends_at' => $this->grace_period_ends_at?->toIso8601String(),
            'current_period_ends_at' => $this->current_period_ends_at?->toIso8601String(),
            // Module 29 (Phase B23) — full billing detail lives at /billing.
            'billing_interval' => $this->billing_interval->value,
            'cancel_at_period_end' => (bool) $this->cancel_at_period_end,
        ];
    }
}
