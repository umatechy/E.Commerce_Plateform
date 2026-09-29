<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupScope;
use App\Domain\Events\Models\OutboxEvent;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Monitoring\Models\HealthStatus;
use App\Domain\Monitoring\Services\StoreHealthReport;
use App\Domain\Monitoring\Services\StoreHealthService;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Packages\Services\EntitlementService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase B21 — Module 24 store-health checks, each derived from the
 * authoritative record of its own module.
 */
final class StoreHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    /** An active store with an active subscription and a fresh verified backup: nothing to report. */
    private function healthyStore(?Package $package = null): Store
    {
        $store = Store::factory()->create(['status' => 'active']);
        Subscription::factory()->for($store)->for($package ?? Package::factory()->create())->create(['status' => SubscriptionStatus::Active]);
        Backup::factory()->create(['store_id' => $store->id, 'verified_at' => now()->subHour()]);
        app(TenantContext::class)->resolveToStore($store->id);

        return $store;
    }

    private function evaluate(Store $store): StoreHealthReport
    {
        return app(StoreHealthService::class)->evaluate($store);
    }

    private function check(StoreHealthReport $report, string $key): array
    {
        return collect($report->checksToArray())->firstWhere('key', $key);
    }

    public function test_a_healthy_store_reports_ok_on_every_check(): void
    {
        $report = $this->evaluate($this->healthyStore());

        $this->assertSame(HealthStatus::Ok, $report->overall);
        $this->assertSame(
            ['setup', 'subscription', 'resource_usage', 'domains', 'inventory', 'event_delivery', 'notifications', 'payment_webhooks', 'developer_webhooks', 'backups'],
            array_column($report->checksToArray(), 'key'),
        );
    }

    public function test_evaluating_a_store_outside_its_own_tenant_context_is_refused(): void
    {
        $storeA = $this->healthyStore();
        $storeB = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($storeA->id);

        $this->expectException(\LogicException::class);
        $this->evaluate($storeB);
    }

    public function test_a_store_without_a_subscription_is_critical(): void
    {
        $store = Store::factory()->create(['status' => 'active']);
        app(TenantContext::class)->resolveToStore($store->id);

        $report = $this->evaluate($store);

        $this->assertSame('critical', $this->check($report, 'subscription')['status']);
        $this->assertSame(HealthStatus::Critical, $report->overall);
    }

    public function test_past_due_is_a_warning_and_suspended_is_critical(): void
    {
        $store = $this->healthyStore();
        $subscription = Subscription::query()->where('store_id', $store->id)->sole();

        $subscription->update(['status' => SubscriptionStatus::PastDue]);
        $this->assertSame('warning', $this->check($this->evaluate($store), 'subscription')['status']);

        $subscription->update(['status' => SubscriptionStatus::Suspended]);
        $this->assertSame('critical', $this->check($this->evaluate($store), 'subscription')['status']);
    }

    public function test_a_trial_about_to_end_is_a_warning(): void
    {
        $store = $this->healthyStore();
        Subscription::query()->where('store_id', $store->id)->update([
            'status' => SubscriptionStatus::Trialing->value,
            'trial_ends_at' => now()->addDays(2),
        ]);

        $check = $this->check($this->evaluate($store), 'subscription');

        $this->assertSame('warning', $check['status']);
        $this->assertSame(2, $check['metrics']['trial_days_left']);
    }

    public function test_usage_near_and_at_a_package_limit_is_reported_from_the_real_counters(): void
    {
        $package = Package::factory()->create();
        $package->entitlements()->create(['key' => 'max_products', 'type' => EntitlementType::UsageLimit, 'limit_value' => 10]);
        $package->entitlements()->create(['key' => 'max_staff', 'type' => EntitlementType::UsageLimit, 'limit_value' => 5]);
        $store = $this->healthyStore($package);
        $entitlements = app(EntitlementService::class);

        $entitlements->recordUsage('max_products', 8);
        $check = $this->check($this->evaluate($store), 'resource_usage');
        $this->assertSame('warning', $check['status']);
        $this->assertSame(['current' => 8, 'limit' => 10, 'percent' => 80, 'status' => 'warning'], $check['metrics']['limits']['max_products']);
        $this->assertSame('ok', $check['metrics']['limits']['max_staff']['status']);

        $entitlements->recordUsage('max_products', 2);
        $this->assertSame('critical', $this->check($this->evaluate($store), 'resource_usage')['status']);
    }

    public function test_unlimited_entitlements_are_not_treated_as_limits(): void
    {
        $package = Package::factory()->create();
        $package->entitlements()->create(['key' => 'max_products', 'type' => EntitlementType::UsageLimit, 'is_unlimited' => true]);
        $store = $this->healthyStore($package);
        app(EntitlementService::class)->recordUsage('max_products', 1000);

        $check = $this->check($this->evaluate($store), 'resource_usage');

        $this->assertSame('ok', $check['status']);
        $this->assertSame([], $check['metrics']['limits']);
    }

    public function test_a_store_without_an_active_primary_domain_is_critical(): void
    {
        $store = $this->healthyStore();
        \App\Domain\Domains\Models\Domain::query()->update(['status' => 'suspended']);

        $this->assertSame('critical', $this->check($this->evaluate($store), 'domains')['status']);
    }

    public function test_low_and_out_of_stock_items_are_a_warning(): void
    {
        $store = $this->healthyStore();
        $warehouse = Warehouse::factory()->for($store)->create();
        Inventory::factory()->for($store)->for($warehouse)->create(['on_hand' => 3, 'reserved' => 1, 'reorder_point' => 5]);
        Inventory::factory()->for($store)->for($warehouse)->create(['on_hand' => 2, 'reserved' => 2]);

        $check = $this->check($this->evaluate($store), 'inventory');

        $this->assertSame('warning', $check['status']);
        $this->assertSame(['out_of_stock' => 1, 'low_stock' => 1], $check['metrics']);
    }

    public function test_a_failed_outbox_event_is_critical_and_a_stale_pending_one_is_a_warning(): void
    {
        $store = $this->healthyStore();
        DB::transaction(fn () => app(RecordsOutboxEvents::class)->recordEvent('test.event', [], 'health-outbox-1'));
        $event = OutboxEvent::query()->where('idempotency_key', 'health-outbox-1')->sole();

        OutboxEvent::query()->whereKey($event->id)->update(['created_at' => now()->subHour()]);
        $this->assertSame('warning', $this->check($this->evaluate($store), 'event_delivery')['status']);

        OutboxEvent::query()->whereKey($event->id)->update(['status' => 'failed']);
        $this->assertSame('critical', $this->check($this->evaluate($store), 'event_delivery')['status']);
    }

    public function test_another_stores_failures_never_affect_this_stores_health(): void
    {
        $storeB = Store::factory()->create();
        DB::transaction(fn () => app(RecordsOutboxEvents::class)->recordEventFor($storeB->id, 'test.event', [], 'health-outbox-b'));
        OutboxEvent::query()->withoutTenantScope()->where('idempotency_key', 'health-outbox-b')->update(['status' => 'failed']);

        $storeA = $this->healthyStore();

        $this->assertSame(HealthStatus::Ok, $this->evaluate($storeA)->overall);
    }

    public function test_a_platform_backup_covers_the_store_but_a_stale_one_is_a_warning(): void
    {
        $store = Store::factory()->create(['status' => 'active']);
        Subscription::factory()->for($store)->create(['status' => SubscriptionStatus::Active]);
        app(TenantContext::class)->resolveToStore($store->id);

        $this->assertSame('warning', $this->check($this->evaluate($store), 'backups')['status']); // nothing yet

        Backup::factory()->create(['scope' => BackupScope::Platform, 'store_id' => null, 'verified_at' => now()->subHours(3)]);
        $this->assertSame('ok', $this->check($this->evaluate($store), 'backups')['status']);

        Backup::query()->update(['verified_at' => now()->subDays(5)]);
        $this->assertSame('warning', $this->check($this->evaluate($store), 'backups')['status']);
    }

    public function test_a_developer_webhook_that_later_succeeded_is_not_reported(): void
    {
        $store = $this->healthyStore();
        $subscription = \App\Domain\DeveloperPlatform\Models\WebhookSubscription::factory()->for($store)->create();
        $attempt = fn (string $key, int $number, string $result) => \App\Domain\DeveloperPlatform\Models\WebhookDeliveryAttempt::query()->create([
            'store_id' => $store->id, 'webhook_subscription_id' => $subscription->id, 'event_type' => 'order.created',
            'idempotency_key' => $key, 'attempt_number' => $number, 'result' => $result,
        ]);

        $attempt('delivery-1', 1, 'failed');
        $attempt('delivery-1', 2, 'succeeded'); // the retry got through
        $this->assertSame('ok', $this->check($this->evaluate($store), 'developer_webhooks')['status']);

        $attempt('delivery-2', 1, 'failed');
        $check = $this->check($this->evaluate($store), 'developer_webhooks');
        $this->assertSame('warning', $check['status']);
        $this->assertSame(1, $check['metrics']['undelivered_recently']);
    }
}
