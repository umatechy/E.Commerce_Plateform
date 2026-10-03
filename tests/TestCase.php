<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base test case. Was REFERENCED but never created in Phase B0 — a
 * genuine gap fixed in B1 (see docs/development/b1-inspection-findings.md
 * item B). Without this file, none of the B0 or B1 Feature tests could
 * have executed even with a real PHP/PHPUnit runtime available.
 */
abstract class TestCase extends BaseTestCase
{
    private ?int $actorId = null;

    protected function setUp(): void
    {
        parent::setUp();

        // See config/outbox.php: RefreshDatabase has already opened one
        // transaction per test, which must not count as the caller's own.
        config(['outbox.ambient_transaction_level' => \Illuminate\Support\Facades\DB::transactionLevel()]);
    }

    /**
     * Every simulated HTTP request starts with fresh request-scoped state
     * (TenantContext, ApiKeyContext), exactly like a real request in its
     * own PHP-FPM worker. Without this, a context the test itself resolved
     * leaked into the request, so tests could pass because of the TEST's
     * tenant rather than the one the middleware resolved (this hid a
     * broken Host-header test). The test's own context is put back
     * afterwards so its follow-up assertions keep querying as before.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $testContext = $this->app->resolved(TenantContext::class) ? $this->app->make(TenantContext::class) : null;

        $this->app->forgetScopedInstances();

        try {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        } finally {
            if ($testContext !== null) {
                $this->app->instance(TenantContext::class, $testContext);
            }
        }
    }

    /**
     * StoreObserver seeds every new store's system roles (owner, manager,
     * staff) at creation time, so tests must reuse those rows instead of
     * creating a second role with the same slug — that violates
     * uniq_roles_store_id_slug (found on the first real test run).
     */
    protected function systemRole(Store $store, string $slug): Role
    {
        return Role::query()->withoutTenantScope()
            ->where('store_id', $store->id)
            ->where('slug', $slug)
            ->firstOrFail();
    }

    /**
     * A real users.id for service calls that record who acted (stock
     * movements, payment transactions, restore jobs). Those columns are
     * real foreign keys (ADR-003), so a hard-coded id such as 1 fails
     * whenever no such row exists — RefreshDatabase never resets
     * AUTO_INCREMENT, so it almost never does.
     */
    protected function actorId(): int
    {
        return $this->actorId ??= User::factory()->create()->id;
    }

    /**
     * Makes the store price in this currency (store.default_currency). New
     * stores use PKR (owner decision 2026-10-03); the factories still make
     * USD products and rates, so a test that checks out such products
     * gives its store USD, as a real USD store would have.
     */
    protected function storeCurrency(Store $store, string $currency): void
    {
        \Illuminate\Support\Facades\DB::table('store_settings')->updateOrInsert(
            ['store_id' => $store->id, 'key' => 'store.default_currency'],
            ['value' => json_encode([$currency]), 'created_at' => now(), 'updated_at' => now()],
        );
        \Illuminate\Support\Facades\Cache::forget("settings:store:{$store->id}:store.default_currency");
    }

    /**
     * Gives the store an active subscription to a package granting the
     * given boolean feature entitlements (EntitlementService is server-
     * authoritative, so a store created by the factory has none).
     *
     * @param  list<string>  $featureKeys
     */
    protected function entitle(Store $store, array $featureKeys): Package
    {
        $package = Package::factory()->create();

        foreach ($featureKeys as $key) {
            $package->entitlements()->create(['key' => $key, 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        }

        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);

        return $package;
    }
}
