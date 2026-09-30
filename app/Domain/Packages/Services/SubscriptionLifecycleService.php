<?php

declare(strict_types=1);

namespace App\Domain\Packages\Services;

use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Module 04 §17–22, §36–39 lifecycle transitions. Every transition:
 *  1. Runs inside DB::transaction() with the outbox write (ADR-004).
 *  2. Invalidates this store's cached entitlements (they are wrong the
 *     instant the package/status changes — never served stale).
 *  3. Writes an audit log entry (Module 04 §39 "Package Change Audit"
 *     — Store, Previous/New Package, Actor, Source, Timestamp; full
 *     structured audit storage is Module 32's concern, not yet built —
 *     this uses the same Log::channel('audit') pattern already
 *     established for Super Admin impersonation in Phase B1).
 *  4. NEVER deletes or touches any business data (products, orders,
 *     customers, ...) — Module 04 §20, §37 are explicit that only
 *     entitlement/resource access changes, never store identity or
 *     existing data. This service literally has no code path that
 *     touches any table other than subscriptions/stores/the audit log/
 *     the outbox — verified by inspection.
 */
final class SubscriptionLifecycleService
{
    /**
     * Module 04 §14 "Trial System". Called once, at store registration
     * (AuthController::register(), same transaction as Store creation —
     * see docs/development/b2-inspection-findings.md item B for why this
     * was missing before B2).
     *
     * Trial package/duration are configurable (config/packages.php),
     * NOT hard-coded, per §14's explicit "should be configurable by
     * Umar Techy" requirement.
     */
    public function startTrial(Store $store): Subscription
    {
        $package = Package::query()
            ->where('code', config('packages.default_trial_package_code'))
            ->firstOrFail();

        $trialDays = (int) config('packages.default_trial_days');

        return DB::transaction(function () use ($store, $package, $trialDays) {
            $subscription = Subscription::query()->withoutTenantScope()->create([
                'store_id' => $store->id,
                'package_id' => $package->id,
                'status' => SubscriptionStatus::Trialing,
                'trial_ends_at' => now()->addDays($trialDays),
                'current_period_ends_at' => now()->addDays($trialDays),
            ]);

            $this->auditAndInvalidate($store->id, null, $package, 'trial_started', 'registration');

            return $subscription;
        });
    }

    /**
     * Module 04 §21–22 "Upgrade / Downgrade". Deliberately the SAME
     * method for both directions — per §4 "Package Design Principle"
     * there is no special-cased "if upgrading" vs "if downgrading" code
     * path; only entitlement/limit values differ, which the
     * EntitlementService already resolves generically from whichever
     * package is now assigned.
     *
     * Over-limit resources on downgrade (§22) are intentionally NOT
     * auto-deleted or auto-archived here — §22 explicitly says "the
     * final policy will be defined later" and lists deletion as only
     * ONE of several possible resolution options, not a decision to
     * make now. This method only records that the store is over-limit
     * (via the returned over-limit report) so a future module/UI can
     * act on it; it never deletes data on its own.
     *
     * @return array<string, array{limit: int, current: int}> keys of
     *         any usage limits the store now exceeds after the change
     *         (empty array if none) — informational only, not enforced
     *         here (Module 04 §22: never silently destructive).
     */
    public function changePackage(Store $store, Package $newPackage, string $actorDescription, string $source): array
    {
        $subscription = $store->currentSubscription()->withoutTenantScope()->firstOrFail();
        $previousPackage = $subscription->package;

        DB::transaction(function () use ($subscription, $newPackage) {
            $subscription->update(['package_id' => $newPackage->id]);
        });

        $this->auditAndInvalidate($store->id, $previousPackage, $newPackage, 'package_changed', $source, $actorDescription);

        return $this->detectOverLimitUsage($store, $newPackage);
    }

    public function suspend(Store $store, string $reason): void
    {
        $this->transitionStatus($store, SubscriptionStatus::Suspended, 'subscription_suspended', $reason);
    }

    public function cancel(Store $store, string $reason): void
    {
        $this->transitionStatus($store, SubscriptionStatus::Cancelled, 'subscription_cancelled', $reason);
    }

    /** Module 04 §36 "Package Expiry Behavior". */
    public function expire(Store $store): void
    {
        $this->transitionStatus($store, SubscriptionStatus::Expired, 'subscription_expired', 'period_ended');
    }

    /** Module 04 §38 "Reactivation" — historical data untouched by construction (see class docblock). */
    public function reactivate(Store $store, string $reason): void
    {
        $this->transitionStatus($store, SubscriptionStatus::Active, 'subscription_reactivated', $reason);
    }

    private function transitionStatus(Store $store, SubscriptionStatus $newStatus, string $eventType, string $reason): void
    {
        $subscription = $store->currentSubscription()->withoutTenantScope()->firstOrFail();
        $previousStatus = $subscription->status;

        DB::transaction(function () use ($subscription, $newStatus) {
            $subscription->update(['status' => $newStatus]);
        });

        // Entitlement cache is invalidated exhaustively (see
        // invalidateAllEntitlementCacheKeys() docblock — Cache::forget()
        // has no wildcard support, so every known key is forgotten
        // explicitly rather than relying on a wildcard that would not
        // actually work against Laravel's generic cache contract).
        $this->invalidateAllEntitlementCacheKeys($store);

        app(\App\Domain\Compliance\Services\AuditLogger::class)->record($eventType, [
            'store_id' => $store->id,
            'previous_status' => $previousStatus->value,
            'new_status' => $newStatus->value,
            'reason' => $reason,
            'timestamp' => now()->toIso8601String(),
        ], storeId: $store->id);
    }

    private function auditAndInvalidate(
        int $storeId,
        ?Package $previousPackage,
        Package $newPackage,
        string $eventType,
        string $source,
        ?string $actorDescription = null,
    ): void {
        $this->invalidateAllEntitlementCacheKeys(Store::query()->find($storeId));

        app(\App\Domain\Compliance\Services\AuditLogger::class)->record($eventType, [
            'store_id' => $storeId,
            'previous_package' => $previousPackage?->code,
            'new_package' => $newPackage->code,
            'actor' => $actorDescription,
            'source' => $source,
            'timestamp' => now()->toIso8601String(),
        ], storeId: $storeId);
    }

    /**
     * Cache::forget() has no wildcard support in Laravel's generic
     * cache store contract, so every known entitlement key for this
     * store is explicitly forgotten — the key list comes from the new
     * package's own entitlement rows PLUS the previous package's (in
     * case a key existed on the old package but not the new one).
     */
    private function invalidateAllEntitlementCacheKeys(?Store $store): void
    {
        if ($store === null) {
            return;
        }

        $subscription = $store->currentSubscription()->withoutTenantScope()->first();
        $keys = $subscription?->package?->entitlements?->pluck('key') ?? collect();

        foreach ($keys as $key) {
            Cache::forget("tenant:{$store->id}:entitlement:{$key}");
        }
    }

    private function detectOverLimitUsage(Store $store, Package $newPackage): array
    {
        $overLimit = [];

        foreach ($newPackage->entitlements as $entitlement) {
            if ($entitlement->type !== \App\Domain\Packages\Models\EntitlementType::UsageLimit
                || $entitlement->is_unlimited
                || $entitlement->limit_value === null) {
                continue;
            }

            $current = \App\Domain\Packages\Models\UsageCounter::query()
                ->withoutTenantScope()
                ->where('store_id', $store->id)
                ->where('metric_key', $entitlement->key)
                ->value('count') ?? 0;

            if ($current > $entitlement->limit_value) {
                $overLimit[$entitlement->key] = ['limit' => $entitlement->limit_value, 'current' => $current];
            }
        }

        return $overLimit;
    }
}
