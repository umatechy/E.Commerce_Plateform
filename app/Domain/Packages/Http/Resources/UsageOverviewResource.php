<?php

declare(strict_types=1);

namespace App\Domain\Packages\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 04 §40–41 "Customer Package Visibility / Usage Dashboard".
 * $this->resource is expected to be an array of
 * [key => ['limit' => ?int, 'current' => int, 'unlimited' => bool, 'remaining' => ?int]]
 * assembled by SubscriptionController — see that controller for why
 * this is built there rather than as a Model-backed resource (usage
 * data spans multiple entitlement keys with no single backing row).
 */
final class UsageOverviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
