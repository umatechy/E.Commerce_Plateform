<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Billing\Models\InvoicePayment;
use App\Domain\Billing\Models\InvoiceStatus;
use App\Domain\Billing\Models\PackagePrice;
use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\SubscriptionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B23 — Module 29 platform administration: prices, the invoice
 * ledger across stores, recording money (idempotently), voiding and
 * extending, and the revenue summary.
 */
final class SuperAdminBillingTest extends TestCase
{
    use InteractsWithBilling, RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-03-01 09:00:00'));
        $this->superAdmin = User::factory()->create(['platform_role' => 'support_agent']);
    }

    /** @return array{0: \App\Domain\Packages\Models\Subscription, 1: \App\Domain\Billing\Models\Invoice} an overdue store and its invoice */
    private function overdueStore(int $amount = 2900): array
    {
        [, , $subscription] = $this->billedStore($amount, periodEnd: CarbonImmutable::parse('2026-03-01 09:00:00'));
        $this->runBilling();

        return [$subscription->refresh(), $this->invoicesOf($subscription)->sole()];
    }

    private function payment(array $overrides = []): array
    {
        return ['amount_minor' => 2900, 'method' => 'bank_transfer', 'reference' => 'HBL-7781', 'idempotency_key' => 'pay-0001-abcd', ...$overrides];
    }

    public function test_prices_are_created_updated_and_audited(): void
    {
        Package::factory()->create(['code' => 'premium']);

        $created = $this->actingAs($this->superAdmin)->postJson('/api/v1/super-admin/billing/prices', [
            'package_code' => 'premium', 'billing_interval' => 'monthly', 'currency' => 'pkr', 'amount_minor' => 499900,
        ])->assertCreated()->assertJsonPath('data.currency', 'PKR')->assertJsonPath('data.amount_minor', 499900);

        $this->actingAs($this->superAdmin)->postJson('/api/v1/super-admin/billing/prices', [
            'package_code' => 'premium', 'billing_interval' => 'monthly', 'currency' => 'PKR', 'amount_minor' => 549900,
        ])->assertOk()->assertJsonPath('data.amount_minor', 549900);
        $this->assertSame(1, PackagePrice::query()->count());

        $this->actingAs($this->superAdmin)->patchJson('/api/v1/super-admin/billing/prices/'.$created->json('data.id'), ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->actingAs($this->superAdmin)->postJson('/api/v1/super-admin/billing/prices', [
            'package_code' => 'missing', 'billing_interval' => 'weekly', 'currency' => 'RUPEES', 'amount_minor' => -1,
        ])->assertStatus(422)->assertJsonValidationErrors(['package_code', 'billing_interval', 'currency', 'amount_minor']);

        $this->assertSame(3, AuditLog::query()->where('action', 'super_admin.billing.price_saved')->count());
        $this->actingAs($this->superAdmin)->getJson('/api/v1/super-admin/billing/prices')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_invoice_ledger_spans_stores_and_filters(): void
    {
        [, $overdue] = $this->overdueStore();
        [$store] = $this->billedStore(4900, periodEnd: CarbonImmutable::parse('2026-03-05 09:00:00'));
        $this->runBilling();

        $this->actingAs($this->superAdmin)->getJson('/api/v1/super-admin/billing/invoices')
            ->assertOk()->assertJsonPath('data.total', 2);
        $this->actingAs($this->superAdmin)->getJson('/api/v1/super-admin/billing/invoices?overdue=1')
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $overdue->public_id);
        $this->actingAs($this->superAdmin)->getJson("/api/v1/super-admin/billing/invoices?store={$store->public_id}")
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.store.name', 'Acme Goods');
        $this->actingAs($this->superAdmin)->getJson("/api/v1/super-admin/billing/invoices/{$overdue->public_id}")
            ->assertOk()->assertJsonPath('data.is_overdue', true)->assertJsonPath('data.store.id', $overdue->store->public_id);
    }

    public function test_recording_payments_is_idempotent_and_settles_the_invoice(): void
    {
        [$subscription, $invoice] = $this->overdueStore();
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->status);
        $url = "/api/v1/super-admin/billing/invoices/{$invoice->public_id}/payments";

        $this->actingAs($this->superAdmin)->postJson($url, $this->payment(['amount_minor' => 1000]))
            ->assertCreated()->assertJsonPath('data.invoice.amount_due_minor', 1900)->assertJsonPath('data.invoice.status', 'open');
        // The same request retried (e.g. after a timeout) records nothing new.
        $this->actingAs($this->superAdmin)->postJson($url, $this->payment(['amount_minor' => 1000]))
            ->assertOk()->assertJsonPath('data.invoice.amount_due_minor', 1900);

        $this->actingAs($this->superAdmin)->postJson($url, $this->payment(['amount_minor' => 5000, 'idempotency_key' => 'pay-0002-abcd']))
            ->assertStatus(422)->assertJsonPath('code', 'amount_exceeds_balance');

        $this->actingAs($this->superAdmin)->postJson($url, $this->payment(['amount_minor' => 1900, 'idempotency_key' => 'pay-0003-abcd']))
            ->assertCreated()->assertJsonPath('data.invoice.status', 'paid')->assertJsonCount(2, 'data.invoice.payments');

        $this->assertSame(2, InvoicePayment::query()->withoutTenantScope()->count());
        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertSame($this->superAdmin->id, InvoicePayment::query()->withoutTenantScope()->first()->recorded_by);
        $this->assertSame(2, AuditLog::query()->where('action', 'billing.payment_recorded')->where('store_id', $invoice->store_id)->count());

        $this->actingAs($this->superAdmin)->postJson($url, $this->payment(['idempotency_key' => 'pay-0004-abcd']))
            ->assertStatus(409)->assertJsonPath('code', 'invoice_not_open');
    }

    public function test_an_idempotency_key_cannot_be_reused_for_another_invoice(): void
    {
        [, $first] = $this->overdueStore();
        [, $second] = $this->overdueStore();

        $this->actingAs($this->superAdmin)->postJson("/api/v1/super-admin/billing/invoices/{$first->public_id}/payments", $this->payment(['amount_minor' => 100]))->assertCreated();
        $this->actingAs($this->superAdmin)->postJson("/api/v1/super-admin/billing/invoices/{$second->public_id}/payments", $this->payment(['amount_minor' => 100]))
            ->assertStatus(409)->assertJsonPath('code', 'idempotency_key_reused');
        $this->actingAs($this->superAdmin)->postJson("/api/v1/super-admin/billing/invoices/{$second->public_id}/payments", ['amount_minor' => 100, 'method' => 'bank_transfer'])
            ->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
    }

    public function test_voiding_waives_the_period_and_is_refused_once_money_was_received(): void
    {
        [$subscription, $invoice] = $this->overdueStore();

        $this->actingAs($this->superAdmin)->postJson("/api/v1/super-admin/billing/invoices/{$invoice->public_id}/void", ['reason' => 'Goodwill credit'])
            ->assertOk()->assertJsonPath('data.status', 'void')->assertJsonPath('data.void_reason', 'Goodwill credit');

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame('2026-04-01', $subscription->current_period_ends_at->format('Y-m-d'));

        [, $partlyPaid] = $this->overdueStore();
        $this->actingAs($this->superAdmin)->postJson("/api/v1/super-admin/billing/invoices/{$partlyPaid->public_id}/payments", $this->payment(['amount_minor' => 500, 'idempotency_key' => 'pay-void-0001']))->assertCreated();
        $this->actingAs($this->superAdmin)->postJson("/api/v1/super-admin/billing/invoices/{$partlyPaid->public_id}/void", ['reason' => 'x'])
            ->assertStatus(409)->assertJsonPath('code', 'invoice_partially_paid');
    }

    public function test_extending_the_due_date_lifts_the_dunning_stage(): void
    {
        [$subscription, $invoice] = $this->overdueStore();
        $url = "/api/v1/super-admin/billing/invoices/{$invoice->public_id}/extend-due-date";

        $this->actingAs($this->superAdmin)->postJson($url, ['due_at' => '2026-02-01', 'reason' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('due_at');

        $this->actingAs($this->superAdmin)->postJson($url, ['due_at' => '2026-03-10 00:00:00', 'reason' => 'Bank holiday'])
            ->assertOk()->assertJsonPath('data.is_overdue', false);

        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertSame('Bank holiday', AuditLog::query()->where('action', 'billing.invoice_due_date_extended')->sole()->contextData()['reason']);

        $this->travelTo(CarbonImmutable::parse('2026-03-10 00:00:00'));
        $this->runBilling();
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->refresh()->status);
    }

    public function test_the_summary_reports_revenue_per_currency(): void
    {
        [, $invoice] = $this->overdueStore(2900);
        $this->billedStore(12000, status: 'active', interval: 'yearly');
        $this->actingAs($this->superAdmin)->postJson("/api/v1/super-admin/billing/invoices/{$invoice->public_id}/payments", $this->payment(['amount_minor' => 900]))->assertCreated();

        $response = $this->actingAs($this->superAdmin)->getJson('/api/v1/super-admin/billing/summary')->assertOk();

        $response->assertJsonPath('data.currencies.0.currency', 'USD')
            ->assertJsonPath('data.currencies.0.outstanding_minor', 2000)
            ->assertJsonPath('data.currencies.0.overdue_minor', 2000)
            ->assertJsonPath('data.currencies.0.open_invoices', 1)
            ->assertJsonPath('data.currencies.0.collected_this_month_minor', 900)
            // past_due monthly 2900 + active yearly 12000/12
            ->assertJsonPath('data.currencies.0.mrr_minor', 3900)
            ->assertJsonPath('data.subscriptions_by_status.past_due', 1);
    }

    public function test_store_staff_cannot_reach_platform_billing(): void
    {
        [, $owner, , ] = $this->billedStore();
        [, $invoice] = $this->overdueStore();

        $this->actingAs($owner)->getJson('/api/v1/super-admin/billing/invoices')->assertForbidden();
        $this->actingAs($owner)->postJson("/api/v1/super-admin/billing/invoices/{$invoice->public_id}/payments", $this->payment())->assertForbidden();
        $this->assertSame(InvoiceStatus::Open, $invoice->refresh()->status);
    }
}
