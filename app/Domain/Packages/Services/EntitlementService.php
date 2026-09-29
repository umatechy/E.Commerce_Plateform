<?php

declare(strict_types=1);

namespace App\Domain\Packages\Services;

use App\Domain\Packages\Exceptions\FeatureNotEntitledException;
use App\Domain\Packages\Exceptions\SubscriptionInactiveException;
use App\Domain\Packages\Exceptions\UsageLimitExceededException;
use App\Domain\Packages\Models\EntitlementEnforcement;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\PackageEntitlement;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\UsagePeriod;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * Server-authoritative entitlement checks (Module 04 §6 "Centralized
 * Entitlement Service", §29 "Package Access Evaluation"). ALL
 * package/entitlement/usage-limit/subscription-state checks in the
 * application MUST go through this service — individual modules must
 * not implement their own inconsistent package rules (§6).
 *
 * The two original B0/B1 methods (hasFeature, limitFor) keep their
 * original signatures and semantics — every method added in B2 is
 * additive, per docs/development/b2-inspection-findings.md item B.
 */
final class EntitlementService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly UsageTrackingService $usage,
    ) {}

    public function hasFeature(string $key): bool
    {
        $entitlement = $this->entitlementFor($key);

        return $entitlement !== null
            && $entitlement['type'] === EntitlementType::Feature->value
            && (bool) $entitlement['boolean_value'];
    }

    /**
     * @throws FeatureNotEntitledException|SubscriptionInactiveException
     */
    public function assertFeatureEntitled(string $key): void
    {
        $this->assertSubscriptionActive();

        if (! $this->hasFeature($key)) {
            throw new FeatureNotEntitledException($key);
        }
    }

    public function limitFor(string $key): ?int
    {
        $entitlement = $this->entitlementFor($key);

        if ($entitlement === null || $entitlement['type'] !== EntitlementType::UsageLimit->value) {
            return null;
        }

        return $entitlement['is_unlimited'] ? null : $entitlement['limit_value'];
    }

    public function isUnlimited(string $key): bool
    {
        $entitlement = $this->entitlementFor($key);

        return $entitlement !== null
            && $entitlement['type'] === EntitlementType::UsageLimit->value
            && (bool) $entitlement['is_unlimited'];
    }

    public function enforcementFor(string $key): EntitlementEnforcement
    {
        $entitlement = $this->entitlementFor($key);
        $value = $entitlement['enforcement'] ?? null;

        // Deliberate safe default: an unconfigured enforcement mode on a
        // usage_limit entitlement is treated as Hard (block), never Soft
        // — a missing config value must never silently become the more
        // permissive option (Module 04 §10's exact behavior is
        // per-resource; Hard is the safe default in the absence of an
        // explicit choice).
        return $value !== null ? EntitlementEnforcement::from($value) : EntitlementEnforcement::Hard;
    }

    public function periodFor(string $key): UsagePeriod
    {
        $entitlement = $this->entitlementFor($key);
        $value = $entitlement['period'] ?? null;

        return $value !== null ? UsagePeriod::from($value) : UsagePeriod::Persistent;
    }

    public function currentUsage(string $key): int
    {
        return $this->usage->currentUsage($key, $this->periodFor($key));
    }

    /**
     * @return int|null null means unlimited (never blocked).
     */
    public function remaining(string $key): ?int
    {
        $limit = $this->limitFor($key);

        return $limit === null ? null : max(0, $limit - $this->currentUsage($key));
    }

    public function isWithinLimit(string $key, int $additional = 1): bool
    {
        $limit = $this->limitFor($key);

        return $limit === null || ($this->currentUsage($key) + $additional) <= $limit;
    }

    /**
     * @throws UsageLimitExceededException only when the entitlement's
     *         enforcement is Hard; a Soft-enforced limit never throws —
     *         callers that need to show a warning use isWithinLimit()
     *         explicitly for that (Module 04 §10's documented
     *         distinction).
     */
    public function assertWithinLimit(string $key, int $additional = 1): void
    {
        $limit = $this->limitFor($key);

        if ($limit === null) {
            return; // unlimited — see docs/architecture/b2-packages-entitlements.md
        }

        if (($this->currentUsage($key) + $additional) > $limit
            && $this->enforcementFor($key) === EntitlementEnforcement::Hard) {
            throw new UsageLimitExceededException($key, $limit);
        }
    }

    /**
     * Combined check matching Module 04 §29 steps 4–6 exactly
     * (Subscription valid? → Feature entitled? → Usage within limit?).
     * Callers performing a metered, feature-gated operation should call
     * this ONCE before proceeding, then call recordUsage() only after
     * the operation actually succeeds (see class docblock on
     * UsageTrackingService — never increment before success).
     *
     * @throws SubscriptionInactiveException|FeatureNotEntitledException|UsageLimitExceededException
     */
    public function assertCanUse(string $featureKey, ?string $usageKey = null, int $additional = 1): void
    {
        $this->assertFeatureEntitled($featureKey);

        if ($usageKey !== null) {
            $this->assertWithinLimit($usageKey, $additional);
        }
    }

    public function recordUsage(string $usageKey, int $by = 1): void
    {
        $this->usage->increment($usageKey, $this->periodFor($usageKey), $by);
    }

    public function releaseUsage(string $usageKey, int $by = 1): void
    {
        $this->usage->decrement($usageKey, $this->periodFor($usageKey), $by);
    }

    /**
     * @throws SubscriptionInactiveException
     */
    public function assertSubscriptionActive(): void
    {
        $status = $this->currentSubscriptionStatus();

        if ($status === null || ! $status->grantsAccess()) {
            throw new SubscriptionInactiveException($status ?? \App\Domain\Packages\Models\SubscriptionStatus::Pending);
        }
    }

    private function currentSubscriptionStatus(): ?\App\Domain\Packages\Models\SubscriptionStatus
    {
        return $this->currentSubscription()?->status;
    }

    private function currentSubscription(): ?Subscription
    {
        $storeId = $this->context->storeId();

        return \App\Domain\Tenancy\Models\Store::query()
            ->whereKey($storeId)
            ->with('currentSubscription')
            ->first()
            ?->currentSubscription;
    }

    /**
     * @return array{type: string, enforcement: ?string, period: ?string, boolean_value: bool, limit_value: ?int, is_unlimited: bool}|null
     */
    private function entitlementFor(string $key): ?array
    {
        $storeId = $this->context->storeId();

        // Cached per ADR-001 Layer 6 (tenant-prefixed cache key). MUST be
        // invalidated whenever this store's package/subscription changes
        // — see SubscriptionLifecycleService, which calls
        // Cache::forget() on every transition, so this cache can never
        // serve a stale package's entitlements after a change.
        return Cache::remember(
            "tenant:{$storeId}:entitlement:{$key}",
            now()->addMinutes(5),
            function () use ($key): ?array {
                $row = $this->currentSubscription()
                    ?->package
                    ?->entitlements
                    ?->firstWhere('key', $key);

                if ($row === null) {
                    return null;
                }

                // NOTE: Eloquent's Model class has no ->only() method
                // (that's a Collection method) — this was a latent bug
                // carried from B0/B1's original EntitlementService,
                // caught during this B2 review and fixed here by
                // converting to array first, then picking keys via
                // Arr::only(), which DOES exist and is what B0/B1
                // actually needed.
                return \Illuminate\Support\Arr::only($row->toArray(), [
                    'type', 'enforcement', 'period', 'boolean_value', 'limit_value', 'is_unlimited',
                ]);
            }
        );
    }
}
