<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Services;

use App\Domain\Packages\Models\Subscription;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Storefront\Models\StorefrontAvailability;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreStatus;

/**
 * Module 05 — may shoppers see this store? Evaluated live on every
 * request (never cached), so a suspension or a lapsed subscription
 * closes the storefront immediately. The data itself is never touched:
 * a closed store reopens exactly as it was (Module 04 §20/§37).
 */
final class StorefrontGate
{
    public function __construct(private readonly ConfigService $config) {}

    public function availability(Store $store): StorefrontAvailability
    {
        if ($this->config->get('platform.maintenance_mode') === true) {
            return StorefrontAvailability::Maintenance;
        }

        if ($store->status === StoreStatus::PendingSetup) {
            return StorefrontAvailability::NotLaunched;
        }

        if ($store->status !== StoreStatus::Active || ! $this->subscriptionGrantsAccess($store)) {
            return StorefrontAvailability::Unavailable;
        }

        return StorefrontAvailability::Open;
    }

    public function subscriptionGrantsAccess(Store $store): bool
    {
        $subscription = Subscription::query()->withoutTenantScope()->where('store_id', $store->id)->first();

        return $subscription !== null && $subscription->status->grantsAccess();
    }
}
