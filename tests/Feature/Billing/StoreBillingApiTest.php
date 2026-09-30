<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Billing\Models\PackagePrice;
use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase B23 — Module 29, the store owner's side: plan, upcoming charge,
 * own invoices only, and cancel / resume / interval changes that never
 * take effect before the paid period ends.
 */
final class StoreBillingApiTest extends TestCase
{
    use InteractsWithBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-03-01 09:00:00'));
    }

    /** @param list<string> $permissionKeys */
    private function staffWith(Store $store, array $permissionKeys): User
    {
        $role = Role::factory()->for($store)->create(['slug' => 'custom-'.uniqid()]);

        foreach ($permissionKeys as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['group' => 'billing', 'description' => 'x']);
            DB::table('permission_role')->insert(['role_id' => $role->id, 'permission_id' => $permission->id]);
        }

        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_the_owner_sees_the_plan_the_upcoming_charge_and_the_balance(): void
    {
        config(['billing.tax_rate_bps' => 1000]);
        [, $owner, $subscription] = $this->billedStore(2900, periodEnd: CarbonImmutable::parse('2026-03-05 09:00:00'));
        $this->runBilling();

        $response = $this->actingAs($owner)->getJson('/api/v1/billing');

        $response->assertOk()
            ->assertJsonPath('data.subscription.status', 'trialing')
            ->assertJsonPath('data.subscription.package.name', 'Business')
            ->assertJsonPath('data.subscription.billing_interval', 'monthly')
            ->assertJsonPath('data.subscription.cancel_at_period_end', false)
            ->assertJsonPath('data.upcoming.subtotal_minor', 2900)
            ->assertJsonPath('data.upcoming.tax_minor', 290)
            ->assertJsonPath('data.upcoming.total_minor', 3190)
            ->assertJsonPath('data.balance.open_invoices', 1)
            ->assertJsonPath('data.balance.overdue_invoices', 0)
            ->assertJsonPath('data.balance.amount_due_minor', 3190);
    }

    public function test_the_owner_lists_and_reads_only_their_own_invoices(): void
    {
        [, $owner, $subscription] = $this->billedStore(2900, periodEnd: CarbonImmutable::parse('2026-03-05 09:00:00'));
        [, , $otherSubscription] = $this->billedStore(4900, periodEnd: CarbonImmutable::parse('2026-03-05 09:00:00'));
        $this->runBilling();
        $own = $this->invoicesOf($subscription)->sole();
        $foreign = $this->invoicesOf($otherSubscription)->sole();

        $this->actingAs($owner)->getJson('/api/v1/billing/invoices')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $own->public_id)
            ->assertJsonMissingPath('data.data.0.store_id');

        $this->actingAs($owner)->getJson("/api/v1/billing/invoices/{$own->public_id}")
            ->assertOk()
            ->assertJsonPath('data.number', $own->number)
            ->assertJsonPath('data.amount_due_minor', 2900)
            ->assertJsonPath('data.lines.0.amount_minor', 2900)
            ->assertJsonPath('data.payments', []);

        $this->actingAs($owner)->getJson("/api/v1/billing/invoices/{$foreign->public_id}")->assertNotFound();
        $this->actingAs($owner)->getJson('/api/v1/billing/invoices?status=bogus')->assertStatus(422);
    }

    public function test_billing_requires_the_billing_permissions(): void
    {
        [$store] = $this->billedStore();
        $manager = User::factory()->create();
        $store->users()->attach($manager, ['role_id' => $this->systemRole($store, 'manager')->id, 'status' => 'active']);
        $viewer = $this->staffWith($store, ['billing.view']);

        $this->actingAs($manager)->getJson('/api/v1/billing')->assertForbidden();
        $this->actingAs($viewer)->getJson('/api/v1/billing')->assertOk();
        $this->actingAs($viewer)->postJson('/api/v1/billing/cancel')->assertForbidden();
        $this->actingAs($this->staffWith($store, ['billing.manage']))->postJson('/api/v1/billing/cancel')->assertOk();
    }

    public function test_a_customer_token_cannot_reach_store_billing(): void
    {
        [$store] = $this->billedStore();
        $token = Customer::factory()->for($store)->create(['password' => Hash::make('x')])->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/billing')->assertUnauthorized();
    }

    public function test_cancel_and_resume_are_scheduled_changes_and_audited(): void
    {
        [$store, $owner, $subscription] = $this->billedStore(status: 'active');

        $this->actingAs($owner)->postJson('/api/v1/billing/cancel', ['reason' => 'Moving platforms'])
            ->assertOk()->assertJsonPath('data.cancel_at_period_end', true);
        $this->actingAs($owner)->postJson('/api/v1/billing/cancel')
            ->assertStatus(409)->assertJsonPath('code', 'already_scheduled');

        $this->assertSame('active', $subscription->refresh()->status->value); // nothing ends before the period does
        $entry = AuditLog::query()->where('action', 'billing.cancellation_scheduled')->sole();
        $this->assertSame($store->id, $entry->store_id);
        $this->assertSame('Moving platforms', $entry->contextData()['reason']);

        $this->actingAs($owner)->postJson('/api/v1/billing/resume')
            ->assertOk()->assertJsonPath('data.cancel_at_period_end', false);
        $this->actingAs($owner)->postJson('/api/v1/billing/resume')
            ->assertStatus(409)->assertJsonPath('code', 'not_scheduled');
    }

    public function test_changing_the_interval_needs_a_price_and_an_uninvoiced_next_period(): void
    {
        [, $owner, $subscription, $package] = $this->billedStore(2900, status: 'active', periodEnd: CarbonImmutable::parse('2026-03-20 09:00:00'));

        $this->actingAs($owner)->putJson('/api/v1/billing/interval', ['billing_interval' => 'yearly'])
            ->assertStatus(422)->assertJsonPath('code', 'no_price_for_interval');
        $this->actingAs($owner)->putJson('/api/v1/billing/interval', ['billing_interval' => 'monthly'])
            ->assertStatus(422)->assertJsonPath('code', 'interval_unchanged');
        $this->actingAs($owner)->putJson('/api/v1/billing/interval', ['billing_interval' => 'weekly'])
            ->assertStatus(422)->assertJsonValidationErrors('billing_interval');

        PackagePrice::query()->create(['package_id' => $package->id, 'billing_interval' => 'yearly', 'currency' => 'USD', 'amount_minor' => 29000]);

        $this->actingAs($owner)->putJson('/api/v1/billing/interval', ['billing_interval' => 'yearly'])
            ->assertOk()->assertJsonPath('data.billing_interval', 'yearly');

        $this->travelTo(CarbonImmutable::parse('2026-03-14 09:00:00'));
        $this->runBilling();
        $invoice = $this->invoicesOf($subscription)->sole();
        $this->assertSame(29000, $invoice->total_minor);
        $this->assertSame('2027-03-20', $invoice->period_end->format('Y-m-d'));

        $this->actingAs($owner)->putJson('/api/v1/billing/interval', ['billing_interval' => 'monthly'])
            ->assertStatus(409)->assertJsonPath('code', 'next_invoice_already_issued');
    }

    public function test_registration_starts_a_trial_that_billing_understands(): void
    {
        \App\Domain\Packages\Models\Package::query()->create(['code' => 'basic', 'name' => 'Basic']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'New Owner', 'email' => 'newowner@example.com',
            'password' => 'correct-horse-battery-staple', 'password_confirmation' => 'correct-horse-battery-staple',
            'store_name' => 'New Owner Store',
        ])->assertCreated();

        $subscription = \App\Domain\Packages\Models\Subscription::query()->withoutTenantScope()->sole();
        $this->assertSame('monthly', $subscription->billing_interval->value);
        $this->assertSame(config('billing.currency'), $subscription->currency);
        $this->assertTrue($subscription->billing_anchor_at->equalTo($subscription->trial_ends_at));
        $this->assertNotNull($subscription->current_period_started_at);
    }
}
