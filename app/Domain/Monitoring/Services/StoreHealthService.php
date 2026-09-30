<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Services;

use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupScope;
use App\Domain\DeveloperPlatform\Models\WebhookDeliveryAttempt;
use App\Domain\Domains\Models\Domain;
use App\Domain\Domains\Models\DomainStatus;
use App\Domain\Domains\Models\DomainType;
use App\Domain\Domains\Models\SslStatus;
use App\Domain\Domains\Services\DomainResolverService;
use App\Domain\Events\Models\OutboxEvent;
use App\Domain\Events\Models\OutboxEventStatus;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Monitoring\Models\HealthStatus;
use App\Domain\Monitoring\Models\StoreHealthSnapshot;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Notifications\Models\NotificationStatus;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Packages\Services\EntitlementService;
use App\Domain\Payments\Models\PaymentWebhookEvent;
use App\Domain\Payments\Models\WebhookEventStatus;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreStatus;
use App\Domain\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Module 24 "Store Health, Monitoring & Resource Usage" (Phase B21).
 *
 * Evaluates one store's operational health from the platform's own
 * authoritative records — it never keeps a second copy of any state:
 * subscription and usage come from Module 04 (EntitlementService),
 * domains from Module 19, stock from Module 08, delivery outcomes from
 * the outbox (ADR-004), notifications (Module 21) and both webhook
 * ledgers (Modules 12/31), backups from Module 23. It is read-only
 * apart from snapshot(), which appends a history row.
 *
 * This is TENANT STORE health, deliberately distinct from B20's
 * application health (InfrastructureHealthService) and from host-level
 * infrastructure health, which needs instrumentation this codebase does
 * not own.
 */
final class StoreHealthService
{
    /** Notification outcomes that mean the recipient never got the message. */
    private const FAILED_NOTIFICATION_STATUSES = [
        NotificationStatus::Failed,
        NotificationStatus::Bounced,
        NotificationStatus::Rejected,
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
        private readonly DomainResolverService $domains,
    ) {}

    /**
     * The caller resolves the tenant first (request middleware, Super
     * Admin impersonation, or the snapshot command): every tenant-owned
     * read below relies on BelongsToTenant's scope, so evaluating one
     * store under another store's context would silently mix their data.
     */
    public function evaluate(Store $store): StoreHealthReport
    {
        if ($this->context->isPlatform() || $this->context->storeId() !== $store->id) {
            throw new \LogicException('Store health must be evaluated inside that store\'s own tenant context.');
        }

        $now = CarbonImmutable::now();
        $subscription = Subscription::query()->withoutTenantScope()
            ->where('store_id', $store->id)
            ->with('package.entitlements')
            ->first();

        return new StoreHealthReport($store->id, [
            $this->checkSetup($store),
            $this->checkSubscription($subscription, $now),
            $this->checkResourceUsage($subscription),
            $this->checkDomains($store, $now),
            $this->checkInventory(),
            $this->checkEventDelivery($now),
            $this->checkNotifications($now),
            $this->checkPaymentWebhooks($store, $now),
            $this->checkDeveloperWebhooks($now),
            $this->checkBackups($store, $now),
        ], $now);
    }

    /** Evaluates and appends the result to the store's health history. */
    public function snapshot(Store $store): StoreHealthSnapshot
    {
        $report = $this->evaluate($store);

        return StoreHealthSnapshot::query()->create([
            'store_id' => $store->id,
            'overall_status' => $report->overall,
            'checks' => $report->checksToArray(),
        ]);
    }

    /** Deletes history older than monitoring.store_health.snapshot_retention_days; returns the row count. */
    public function pruneSnapshots(): int
    {
        $cutoff = now()->subDays($this->config('snapshot_retention_days'));

        return StoreHealthSnapshot::query()->withoutTenantScope()->where('created_at', '<', $cutoff)->delete();
    }

    private function checkSetup(Store $store): HealthCheckResult
    {
        $metrics = ['store_status' => $store->status->value];

        return match ($store->status) {
            StoreStatus::Active => new HealthCheckResult('setup', HealthStatus::Ok, 'The store is active.', $metrics),
            StoreStatus::PendingSetup => new HealthCheckResult('setup', HealthStatus::Warning, 'Store setup is not complete yet.', $metrics),
            default => new HealthCheckResult('setup', HealthStatus::Critical, "The store is {$store->status->value}.", $metrics),
        };
    }

    private function checkSubscription(?Subscription $subscription, CarbonImmutable $now): HealthCheckResult
    {
        if ($subscription === null) {
            return new HealthCheckResult('subscription', HealthStatus::Critical, 'The store has no subscription, so no package features are available.');
        }

        $status = $subscription->status;
        $metrics = [
            'status' => $status->value,
            'package' => $subscription->package?->code,
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'current_period_ends_at' => $subscription->current_period_ends_at?->toIso8601String(),
        ];

        if (! $status->grantsAccess()) {
            return new HealthCheckResult('subscription', HealthStatus::Critical, "The subscription is {$status->value} and grants no feature access.", $metrics);
        }

        if (in_array($status, [SubscriptionStatus::PastDue, SubscriptionStatus::GracePeriod], true)) {
            return new HealthCheckResult('subscription', HealthStatus::Warning, "The subscription is {$status->value}; features stay available only until it is settled.", $metrics);
        }

        if ($status === SubscriptionStatus::Trialing && $subscription->trial_ends_at !== null) {
            // Rounded up: 1 day 23 hours left is "2 days", not 1.
            $daysLeft = (int) ceil($now->diffInDays($subscription->trial_ends_at, false));

            if ($daysLeft <= $this->config('trial_warning_days')) {
                return new HealthCheckResult('subscription', HealthStatus::Warning, 'The trial ends '.($daysLeft <= 0 ? 'today' : "in {$daysLeft} day(s)").'.', $metrics + ['trial_days_left' => max(0, $daysLeft)]);
            }
        }

        return new HealthCheckResult('subscription', HealthStatus::Ok, "The subscription is {$status->value}.", $metrics);
    }

    /**
     * Module 04 usage limits of the store's own package. Current usage and
     * limits come straight from EntitlementService — the same numbers that
     * enforcement uses, so this can never disagree with a real refusal.
     */
    private function checkResourceUsage(?Subscription $subscription): HealthCheckResult
    {
        $usage = [];
        $worst = HealthStatus::Ok;
        $warningPercent = $this->config('usage_warning_percent');

        foreach ($subscription?->package->entitlements ?? [] as $entitlement) {
            if ($entitlement->type !== EntitlementType::UsageLimit || $this->entitlements->isUnlimited($entitlement->key)) {
                continue;
            }

            $limit = $this->entitlements->limitFor($entitlement->key);

            if ($limit === null) {
                continue;
            }

            $current = $this->entitlements->currentUsage($entitlement->key);
            $percent = $limit > 0 ? (int) floor($current * 100 / $limit) : ($current > 0 ? 100 : 0);
            $status = match (true) {
                $percent >= 100 => HealthStatus::Critical,
                $percent >= $warningPercent => HealthStatus::Warning,
                default => HealthStatus::Ok,
            };
            $worst = HealthStatus::worstOf([$worst, $status]);

            $usage[$entitlement->key] = ['current' => $current, 'limit' => $limit, 'percent' => $percent, 'status' => $status->value];
        }

        $message = match ($worst) {
            HealthStatus::Critical => 'At least one package limit is fully used; further usage is blocked until the package is upgraded.',
            HealthStatus::Warning => "At least one package limit is {$warningPercent}% used or more.",
            HealthStatus::Ok => $usage === [] ? 'The package has no finite usage limits.' : 'All package limits have headroom.',
        };

        return new HealthCheckResult('resource_usage', $worst, $message, ['limits' => $usage]);
    }

    private function checkDomains(Store $store, CarbonImmutable $now): HealthCheckResult
    {
        $primary = $this->domains->primaryDomainFor($store);

        $customDomains = Domain::query()->where('domain_type', DomainType::CustomDomain->value)->get();
        $expiredVerifications = $customDomains->filter(
            fn (Domain $domain) => $domain->status === DomainStatus::VerificationRequired
                && $domain->verification_token_expires_at !== null
                && $domain->verification_token_expires_at->lessThan($now)
        )->count();
        $activeWithoutSsl = $customDomains->filter(
            fn (Domain $domain) => $domain->status === DomainStatus::Active && $domain->ssl_status !== SslStatus::Active
        )->count();

        $metrics = [
            'primary_domain' => $primary?->normalized_hostname,
            'custom_domains' => $customDomains->count(),
            'expired_verifications' => $expiredVerifications,
            'active_without_ssl' => $activeWithoutSsl,
        ];

        if ($primary === null) {
            return new HealthCheckResult('domains', HealthStatus::Critical, 'The storefront has no active primary domain, so it cannot be reached.', $metrics);
        }

        if ($expiredVerifications > 0 || $activeWithoutSsl > 0) {
            return new HealthCheckResult('domains', HealthStatus::Warning, 'Some custom domains need attention (expired verification or SSL not active).', $metrics);
        }

        return new HealthCheckResult('domains', HealthStatus::Ok, "The storefront is served at {$primary->normalized_hostname}.", $metrics);
    }

    /** Same availability formula as Inventory::available() / isLowStock() (Module 08). */
    private function checkInventory(): HealthCheckResult
    {
        $outOfStock = Inventory::query()->whereRaw('(on_hand - reserved) <= 0')->count();
        $lowStock = Inventory::query()
            ->whereNotNull('reorder_point')
            ->whereRaw('(on_hand - reserved) > 0')
            ->whereRaw('(on_hand - reserved) <= reorder_point')
            ->count();

        $metrics = ['out_of_stock' => $outOfStock, 'low_stock' => $lowStock];

        if ($outOfStock > 0 || $lowStock > 0) {
            return new HealthCheckResult('inventory', HealthStatus::Warning, "{$outOfStock} item(s) out of stock, {$lowStock} at or below their reorder point.", $metrics);
        }

        return new HealthCheckResult('inventory', HealthStatus::Ok, 'No stock shortages.', $metrics);
    }

    /**
     * ADR-004 §17: a failed or backed-up outbox is a first-class signal —
     * those events are the store's notifications and webhooks.
     */
    private function checkEventDelivery(CarbonImmutable $now): HealthCheckResult
    {
        $failed = OutboxEvent::query()
            ->where('status', OutboxEventStatus::Failed->value)
            ->where('created_at', '>=', $now->subHours($this->config('failure_window_hours')))
            ->count();
        $oldestPending = OutboxEvent::query()->where('status', OutboxEventStatus::Pending->value)->min('created_at');
        $oldestPendingMinutes = $oldestPending !== null
            ? (int) floor(CarbonImmutable::parse($oldestPending)->diffInMinutes($now, true))
            : null;
        $stale = $oldestPendingMinutes !== null && $oldestPendingMinutes >= $this->config('outbox_stale_minutes');

        $metrics = ['failed_recently' => $failed, 'oldest_pending_minutes' => $oldestPendingMinutes];

        if ($failed > 0) {
            return new HealthCheckResult('event_delivery', HealthStatus::Critical, "{$failed} business event(s) failed permanently in the last {$this->config('failure_window_hours')} hours.", $metrics);
        }

        if ($stale) {
            return new HealthCheckResult('event_delivery', HealthStatus::Warning, "Business events are waiting for {$oldestPendingMinutes} minutes; the dispatcher may be behind.", $metrics);
        }

        return new HealthCheckResult('event_delivery', HealthStatus::Ok, 'Business events are being delivered.', $metrics);
    }

    private function checkNotifications(CarbonImmutable $now): HealthCheckResult
    {
        $since = $now->subHours($this->config('failure_window_hours'));
        $total = NotificationMessage::query()->where('created_at', '>=', $since)->count();
        $failed = NotificationMessage::query()
            ->where('created_at', '>=', $since)
            ->whereIn('status', array_map(fn (NotificationStatus $s) => $s->value, self::FAILED_NOTIFICATION_STATUSES))
            ->count();

        $metrics = [
            'sent_recently' => $total,
            'failed_recently' => $failed,
            'failure_rate_percent' => $total > 0 ? round($failed * 100 / $total, 1) : null,
        ];

        if ($failed > 0) {
            return new HealthCheckResult('notifications', HealthStatus::Warning, "{$failed} of {$total} notification(s) were not delivered in the last {$this->config('failure_window_hours')} hours.", $metrics);
        }

        return new HealthCheckResult('notifications', HealthStatus::Ok, 'No notification delivery failures.', $metrics);
    }

    /** PaymentWebhookEvent has no tenant scope (it can arrive before its store is known), so the store filter is explicit. */
    private function checkPaymentWebhooks(Store $store, CarbonImmutable $now): HealthCheckResult
    {
        $failed = PaymentWebhookEvent::query()
            ->where('store_id', $store->id)
            ->where('status', WebhookEventStatus::Failed->value)
            ->where('created_at', '>=', $now->subHours($this->config('failure_window_hours')))
            ->count();

        if ($failed > 0) {
            return new HealthCheckResult('payment_webhooks', HealthStatus::Warning, "{$failed} payment provider webhook(s) failed to process; affected payments may show a stale status.", ['failed_recently' => $failed]);
        }

        return new HealthCheckResult('payment_webhooks', HealthStatus::Ok, 'Payment provider webhooks are processing.', ['failed_recently' => 0]);
    }

    /**
     * Developer webhooks (Module 31) are retried, so only a delivery that
     * failed and never later succeeded counts.
     */
    private function checkDeveloperWebhooks(CarbonImmutable $now): HealthCheckResult
    {
        $failedKeys = WebhookDeliveryAttempt::query()
            ->where('result', 'failed')
            ->where('occurred_at', '>=', $now->subHours($this->config('failure_window_hours')))
            ->distinct()
            ->pluck('idempotency_key');
        $undelivered = $failedKeys->isEmpty() ? 0 : $failedKeys->count() - WebhookDeliveryAttempt::query()
            ->where('result', 'succeeded')
            ->whereIn('idempotency_key', $failedKeys)
            ->distinct()
            ->count('idempotency_key');

        if ($undelivered > 0) {
            return new HealthCheckResult('developer_webhooks', HealthStatus::Warning, "{$undelivered} webhook delivery(ies) to developer endpoints have not succeeded.", ['undelivered_recently' => $undelivered]);
        }

        return new HealthCheckResult('developer_webhooks', HealthStatus::Ok, 'Developer webhooks are being delivered.', ['undelivered_recently' => 0]);
    }

    /**
     * Module 23: the newest verified backup that covers this store —
     * its own store-scope backup or any platform-wide one. Backup has no
     * tenant scope, so both filters are explicit.
     */
    private function checkBackups(Store $store, CarbonImmutable $now): HealthCheckResult
    {
        $lastVerifiedAt = Backup::query()
            ->whereNotNull('verified_at')
            ->where(fn ($q) => $q->where('store_id', $store->id)->orWhere('scope', BackupScope::Platform->value))
            ->max('verified_at');

        if ($lastVerifiedAt === null) {
            return new HealthCheckResult('backups', HealthStatus::Warning, 'No verified backup covers this store yet.', ['last_verified_at' => null]);
        }

        $lastVerified = CarbonImmutable::parse($lastVerifiedAt);
        $ageHours = (int) floor($lastVerified->diffInHours($now, true));
        $metrics = ['last_verified_at' => $lastVerified->toIso8601String(), 'age_hours' => $ageHours];

        if ($ageHours >= $this->config('backup_max_age_hours')) {
            return new HealthCheckResult('backups', HealthStatus::Warning, "The newest verified backup is {$ageHours} hours old.", $metrics);
        }

        return new HealthCheckResult('backups', HealthStatus::Ok, 'A recent verified backup exists.', $metrics);
    }

    private function config(string $key): int
    {
        return (int) config("monitoring.store_health.{$key}");
    }
}
