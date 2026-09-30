<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PackagePrice;
use App\Domain\Events\Models\OutboxEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Tenancy\Models\Store;
use Carbon\CarbonImmutable;

/** Shared set-up for the Module 29 (Phase B23) billing tests. */
trait InteractsWithBilling
{
    /**
     * A store with an Owner and a subscription whose current period (the
     * trial, by default) ends at $periodEnd, on a package priced at
     * $amountMinor per month in USD ($amountMinor null = no price).
     *
     * @return array{0: Store, 1: User, 2: Subscription, 3: Package}
     */
    protected function billedStore(
        ?int $amountMinor = 2900,
        string $status = 'trialing',
        ?CarbonImmutable $periodEnd = null,
        string $interval = 'monthly',
    ): array {
        $store = Store::factory()->create(['name' => 'Acme Goods']);
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);
        $package = Package::factory()->create(['name' => 'Business']);

        if ($amountMinor !== null) {
            PackagePrice::query()->create(['package_id' => $package->id, 'billing_interval' => $interval, 'currency' => 'USD', 'amount_minor' => $amountMinor]);
        }

        $periodEnd ??= CarbonImmutable::now()->addDays(14);
        $subscription = Subscription::factory()->for($store)->for($package)->create([
            'status' => $status,
            'trial_ends_at' => $status === 'trialing' ? $periodEnd : null,
            'current_period_started_at' => CarbonImmutable::now(),
            'current_period_ends_at' => $periodEnd,
            'billing_interval' => $interval,
            'currency' => 'USD',
            'billing_anchor_at' => $periodEnd,
        ]);

        return [$store, $owner, $subscription->refresh(), $package];
    }

    protected function runBilling(): void
    {
        $this->artisan('billing:run')->assertSuccessful();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Invoice> */
    protected function invoicesOf(Subscription $subscription): \Illuminate\Database\Eloquent\Collection
    {
        return Invoice::query()->withoutTenantScope()->where('subscription_id', $subscription->id)->orderBy('period_start')->get();
    }

    /** @return list<string> */
    protected function outboxTypes(Store $store): array
    {
        return OutboxEvent::query()->withoutTenantScope()->where('store_id', $store->id)->orderBy('id')->pluck('event_type')->all();
    }
}
