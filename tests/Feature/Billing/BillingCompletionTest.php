<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Billing\Models\BillingReason;
use App\Domain\Billing\Models\CreditNote;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoicePaymentMethod;
use App\Domain\Billing\Models\InvoiceStatus;
use App\Domain\Billing\Models\PackagePrice;
use App\Domain\Billing\Services\InvoiceService;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Packages\Models\UsageCounter;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase B47 — gap G21, Module 29 §42–49, §57, §73–76, §79, §92: plan changes
 * with proration and a preview, account credit, credit notes (refund,
 * account credit, lower balance) with four-eyes approval, payment notices,
 * PDF documents and the platform's own tax rate.
 */
final class BillingCompletionTest extends TestCase
{
    use InteractsWithBilling, RefreshDatabase;

    private Store $store;

    private User $owner;

    private Subscription $subscription;

    private Package $business;

    private Package $premium;

    private Package $basic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-03-01 09:00:00'));
        [$this->store, $this->owner, $this->subscription, $this->business] = $this->billedStore(3000, periodEnd: CarbonImmutable::parse('2026-03-15 09:00:00'));
        $this->premium = $this->package('Premium', 6000, ['reviews.product' => true]);
        $this->basic = $this->package('Basic', 1500, [], ['max_products' => 1]);
        $this->business->entitlements()->create(['key' => 'reviews.product', 'type' => EntitlementType::Feature, 'boolean_value' => true]);

        // The trial ends on 15 March; the first period (15 March – 15 April) is paid.
        $this->travelTo(CarbonImmutable::parse('2026-03-08 09:00:00'));
        $this->runBilling();
        $this->pay($this->invoicesOf($this->subscription)->sole());
        $this->travelTo(CarbonImmutable::parse('2026-03-15 09:00:00'));
        $this->runBilling();
        $this->assertSame(SubscriptionStatus::Active, $this->subscription->refresh()->status);
        app(TenantContext::class)->resolveToStore($this->store->id);
    }

    /** @param array<string, bool> $features @param array<string, int> $limits */
    private function package(string $name, int $price, array $features = [], array $limits = []): Package
    {
        $package = Package::factory()->create(['name' => $name, 'code' => strtolower($name)]);
        PackagePrice::query()->create(['package_id' => $package->id, 'billing_interval' => 'monthly', 'currency' => 'USD', 'amount_minor' => $price]);
        foreach ($features as $key => $on) {
            $package->entitlements()->create(['key' => $key, 'type' => EntitlementType::Feature, 'boolean_value' => $on]);
        }
        foreach ($limits as $key => $limit) {
            $package->entitlements()->create(['key' => $key, 'type' => EntitlementType::UsageLimit, 'limit_value' => $limit, 'is_unlimited' => false]);
        }

        return $package;
    }

    private function pay(Invoice $invoice, ?int $amount = null): void
    {
        app(InvoiceService::class)->recordPayment($invoice, [
            'amount_minor' => $amount ?? $invoice->amountDue(), 'method' => InvoicePaymentMethod::BankTransfer, 'reference' => 'TRX-1',
            'received_at' => CarbonImmutable::now(), 'idempotency_key' => uniqid('pay-'),
        ], null);
    }

    private function platform(string $key, mixed $value): void
    {
        DB::table('platform_settings')->updateOrInsert(['key' => $key], ['value' => json_encode([$value]), 'created_at' => now(), 'updated_at' => now()]);
        Cache::flush();
    }

    private function admin(): User
    {
        return User::factory()->create(['platform_role' => 'super_admin']);
    }

    private function staffSession(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_an_upgrade_is_prorated_charged_at_once_and_keeps_the_new_plan_at_renewal(): void
    {
        $this->platform('billing.tax_rate_bps', 1000);
        $this->travelTo(CarbonImmutable::parse('2026-03-30 09:00:00')); // 16 of the 31 days are left

        $preview = $this->actingAs($this->owner)->getJson('/api/v1/billing/plan-change/preview?package=premium')->assertOk()->json('data');
        $this->assertSame(['upgrade', 'now', null], [$preview['direction'], $preview['timing'], $preview['blocked']]);
        // 6 000 × 16/31 = 3 097; unused 3 000 × 16/31 = 1 548; net 1 549 + 10 % tax.
        $this->assertSame([3097, 1548, 1549, 155, 1704], [$preview['proration']['charge_minor'], $preview['proration']['credit_minor'], $preview['proration']['net_minor'], $preview['proration']['tax_minor'], $preview['proration']['total_minor']]);

        $done = $this->actingAs($this->owner)->postJson('/api/v1/billing/plan-change', ['package' => 'premium'])->assertOk()->json('data');
        $this->assertSame(['now', 1704], [$done['timing'], $done['invoice']['total_minor']]);
        $invoice = Invoice::query()->where('public_id', $done['invoice']['id'])->firstOrFail();
        $this->assertSame([BillingReason::Proration, InvoiceStatus::Open, 1000], [$invoice->billing_reason, $invoice->status, $invoice->tax_rate_bps]);
        $this->assertSame([['charge', 3097], ['credit', 1548]], $invoice->lines()->orderBy('id')->get()->map(fn ($l) => [$l->kind, $l->amount_minor])->all());
        $this->assertSame($this->premium->id, $this->subscription->refresh()->package_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.plan_changed', 'store_id' => $this->store->id]);

        // The renewal is billed for Premium and the package stays Premium after it.
        $this->pay($invoice);
        $this->travelTo(CarbonImmutable::parse('2026-04-08 09:00:00'));
        $this->runBilling();
        $renewal = $this->invoicesOf($this->subscription)->firstWhere('period_start', CarbonImmutable::parse('2026-04-15 09:00:00'));
        $this->assertSame([$this->premium->id, 6000], [$renewal->package_id, $renewal->subtotal_minor]);
        $this->pay($renewal);
        $this->travelTo(CarbonImmutable::parse('2026-04-15 09:00:00'));
        $this->runBilling();
        $this->assertSame($this->premium->id, $this->subscription->refresh()->package_id);
    }

    public function test_a_downgrade_waits_for_the_period_end_and_the_preview_says_what_changes(): void
    {
        UsageCounter::query()->withoutTenantScope()->create(['store_id' => $this->store->id, 'metric_key' => 'max_products', 'count' => 5, 'period_start' => now(), 'period_end' => now()->addYears(50)]);
        $this->travelTo(CarbonImmutable::parse('2026-03-20 09:00:00'));

        $preview = $this->actingAs($this->owner)->getJson('/api/v1/billing/plan-change/preview?package=basic')->assertOk()->json('data');
        $this->assertSame(['downgrade', 'period_end', null], [$preview['direction'], $preview['timing'], $preview['proration']]);
        $this->assertStringStartsWith('2026-04-15', $preview['effective_at']);
        $this->assertSame(['reviews.product'], $preview['features_lost']);
        $this->assertSame(['max_products' => ['limit' => 1, 'current' => 5]], $preview['limits_exceeded']);

        $this->actingAs($this->owner)->postJson('/api/v1/billing/plan-change', ['package' => 'basic'])->assertOk()->assertJsonPath('data.timing', 'period_end');
        $this->assertSame([$this->business->id, $this->basic->id], [$this->subscription->refresh()->package_id, $this->subscription->scheduled_package_id]);
        $this->assertSame('basic', $this->actingAs($this->owner)->getJson('/api/v1/billing')->json('data.subscription.scheduled_package.code'));

        // The renewal is billed for Basic; when that period starts, the store is on Basic. Nothing was deleted.
        $this->travelTo(CarbonImmutable::parse('2026-04-08 09:00:00'));
        $this->runBilling();
        $renewal = $this->invoicesOf($this->subscription)->last();
        $this->assertSame([$this->basic->id, 1500], [$renewal->package_id, $renewal->subtotal_minor]);
        $this->pay($renewal);
        $this->travelTo(CarbonImmutable::parse('2026-04-15 09:10:00'));
        $this->runBilling();
        $this->assertSame([$this->basic->id, null], [$this->subscription->refresh()->package_id, $this->subscription->scheduled_package_id]);
        $this->assertSame(5, (int) UsageCounter::query()->withoutTenantScope()->where('store_id', $this->store->id)->value('count'));
    }

    public function test_an_issued_renewal_is_never_changed_and_open_invoices_block_an_upgrade(): void
    {
        // The Business renewal (3 000) is already issued: a downgrade starts after that period, and the invoice stays.
        $this->travelTo(CarbonImmutable::parse('2026-04-09 09:00:00'));
        $this->runBilling();
        $renewal = $this->invoicesOf($this->subscription)->last();
        $done = $this->actingAs($this->owner)->postJson('/api/v1/billing/plan-change', ['package' => 'basic'])->assertOk()->json('data');
        $this->assertStringStartsWith('2026-05-15', $done['effective_at']);
        $this->assertSame([InvoiceStatus::Open, 3000, $this->business->id], [$renewal->refresh()->status, $renewal->subtotal_minor, $renewal->package_id]);
        // Changing the mind is possible while nothing is billed for the new package yet.
        $this->actingAs($this->owner)->deleteJson('/api/v1/billing/plan-change')->assertNoContent();
        $this->assertNull($this->subscription->refresh()->scheduled_package_id);
        $this->actingAs($this->owner)->deleteJson('/api/v1/billing/plan-change')->assertStatus(422)->assertJsonPath('code', 'nothing_scheduled');

        // An immediate upgrade also charges the issued renewal's difference: 6 000 × 6/31 − 3 000 × 6/31, plus 6 000 − 3 000.
        $preview = $this->actingAs($this->owner)->getJson('/api/v1/billing/plan-change/preview?package=premium')->json('data.proration');
        $this->assertSame([1161 + 6000, 581 + 3000], [$preview['charge_minor'], $preview['credit_minor']]);
        $this->assertStringStartsWith('2026-05-15', $preview['until']);

        // Once the renewal is billed for the scheduled package, the change can no longer be undone.
        $this->pay($renewal);
        $this->travelTo(CarbonImmutable::parse('2026-04-15 09:10:00'));
        $this->runBilling();
        $this->actingAs($this->owner)->postJson('/api/v1/billing/plan-change', ['package' => 'basic'])->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-05-09 09:00:00'));
        $this->runBilling();
        $this->assertSame($this->basic->id, $this->invoicesOf($this->subscription)->last()->package_id);
        $this->actingAs($this->owner)->deleteJson('/api/v1/billing/plan-change')->assertStatus(422)->assertJsonPath('code', 'renewal_issued');

        // An overdue invoice must be paid before an upgrade (the unpaid Basic renewal: still on Business, past due).
        $this->travelTo(CarbonImmutable::parse('2026-05-16 09:00:00'));
        $this->runBilling();
        $this->assertSame([$this->business->id, $this->basic->id], [$this->subscription->refresh()->package_id, $this->subscription->scheduled_package_id]);
        $this->actingAs($this->owner)->postJson('/api/v1/billing/plan-change', ['package' => 'premium'])->assertStatus(422)->assertJsonPath('code', 'pay_open_invoice');
        $this->actingAs($this->owner)->getJson('/api/v1/billing/plan-change/preview?package='.$this->business->code)->assertOk()->assertJsonPath('data.blocked', 'same_package');
    }

    public function test_credit_notes_refund_credit_or_lower_a_balance_with_a_second_person_above_the_threshold(): void
    {
        $paid = $this->invoicesOf($this->subscription)->sole(); // 3 000, paid
        $admin = $this->admin();
        $note = fn (array $body, ?User $by = null) => $this->actingAs($by ?? $admin)->postJson("/api/v1/super-admin/billing/invoices/{$paid->public_id}/credit-notes", ['reason' => 'Service outage', ...$body]);

        $this->assertSame('CN-000001', $note(['amount_minor' => 1000, 'settlement' => 'account_credit'])->assertCreated()->json('data.number'));
        $note(['amount_minor' => 2500, 'settlement' => 'refund', 'refund_method' => 'bank_transfer', 'refund_reference' => 'R-1'])->assertStatus(422)->assertJsonPath('code', 'amount_exceeds_available');
        $note(['amount_minor' => 500, 'settlement' => 'refund'])->assertStatus(422)->assertJsonPath('code', 'refund_details_missing');
        $note(['amount_minor' => 500, 'settlement' => 'reduce_balance'])->assertStatus(422)->assertJsonPath('code', 'invoice_not_open');
        $note(['amount_minor' => 500, 'settlement' => 'refund', 'refund_method' => 'bank_transfer', 'refund_reference' => 'R-1'])->assertCreated()->assertJsonPath('data.status', 'issued');
        $this->assertSame(1500, (int) $paid->refresh()->amount_credited_minor);

        // The account credit pays towards the next invoice.
        $this->staffSession();
        $this->assertSame(1000, $this->actingAs($this->owner)->getJson('/api/v1/billing/credit')->json('data.balance_minor'));
        $this->travelTo(CarbonImmutable::parse('2026-04-08 09:00:00'));
        $this->runBilling();
        $renewal = $this->invoicesOf($this->subscription)->last();
        $this->assertSame([3000, 1000, 2000], [$renewal->subtotal_minor, $renewal->credit_applied_minor, $renewal->total_minor]);
        $this->assertSame(0, $this->actingAs($this->owner)->getJson('/api/v1/billing/credit')->json('data.balance_minor'));

        // Lowering an open invoice's balance to zero settles it.
        $this->staffSession();
        $this->actingAs($admin)->postJson("/api/v1/super-admin/billing/invoices/{$renewal->public_id}/credit-notes", ['reason' => 'Goodwill', 'amount_minor' => 2000, 'settlement' => 'reduce_balance'])->assertCreated();
        $this->assertSame(InvoiceStatus::Paid, $renewal->refresh()->status);

        // Above the threshold a second member of the team approves.
        $this->platform('billing.approval_threshold_minor', 400);
        $pending = $note(['amount_minor' => 450, 'settlement' => 'account_credit'])->assertCreated()->json('data');
        $this->assertSame(['pending_approval', null], [$pending['status'], $pending['number']]);
        $this->actingAs($admin)->postJson("/api/v1/super-admin/billing/credit-notes/{$pending['id']}/approve")->assertForbidden()->assertJsonPath('code', 'same_person');
        $this->staffSession();
        $other = $this->admin();
        $this->actingAs($other)->postJson("/api/v1/super-admin/billing/credit-notes/{$pending['id']}/approve")->assertOk()->assertJsonPath('data.status', 'issued');
        $this->assertSame('CN-000004', CreditNote::query()->withoutTenantScope()->where('public_id', $pending['id'])->value('number'));
        foreach (['billing.credit_note_requested', 'billing.credit_note_issued', 'billing.credit_added'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'store_id' => $this->store->id]);
        }

        // Documents: the store downloads its own PDFs; another store cannot.
        $this->staffSession();
        $pdf = $this->actingAs($this->owner)->get("/api/v1/billing/invoices/{$paid->public_id}/pdf")->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
        $creditNote = CreditNote::query()->withoutTenantScope()->where('number', 'CN-000001')->firstOrFail();
        $this->assertStringStartsWith('%PDF', (string) $this->actingAs($this->owner)->get("/api/v1/billing/credit-notes/{$creditNote->public_id}/pdf")->assertOk()->getContent());
        $this->assertCount(4, $this->actingAs($this->owner)->getJson('/api/v1/billing/credit-notes')->json('data'));
        // Umar Techy staff download the same documents.
        $this->staffSession();
        $this->assertStringStartsWith('%PDF', (string) $this->actingAs($admin)->get("/api/v1/super-admin/billing/invoices/{$paid->public_id}/pdf")->assertOk()->getContent());
        $this->assertStringStartsWith('%PDF', (string) $this->actingAs($admin)->get("/api/v1/super-admin/billing/credit-notes/{$creditNote->public_id}/pdf")->assertOk()->getContent());
        [, $stranger] = $this->billedStore(1000);
        $this->staffSession();
        $this->actingAs($stranger)->get("/api/v1/billing/invoices/{$paid->public_id}/pdf")->assertNotFound();
    }

    public function test_a_store_reports_a_transfer_and_the_team_confirms_or_rejects_it(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-04-08 09:00:00'));
        $this->runBilling();
        $open = $this->invoicesOf($this->subscription)->last();
        $report = fn (array $body) => $this->actingAs($this->owner)->postJson("/api/v1/billing/invoices/{$open->public_id}/payment-notices", [
            'amount_minor' => 3000, 'method' => 'bank_transfer', 'reference' => 'HBL-77812', 'paid_on' => '2026-04-07', ...$body]);

        $report(['amount_minor' => 3001])->assertStatus(422)->assertJsonPath('code', 'amount_exceeds_balance');
        $report(['paid_on' => '2026-05-01'])->assertStatus(422);
        $report(['method' => 'card'])->assertStatus(422);
        $first = $report(['amount_minor' => 1000])->assertCreated()->json('data');
        $second = $report(['amount_minor' => 2000, 'reference' => 'HBL-77813'])->assertCreated()->json('data');
        $report(['amount_minor' => 1])->assertStatus(422); // everything due is reported already
        $this->assertSame(InvoiceStatus::Open, $open->refresh()->status, 'a notice is not a payment');

        $this->staffSession();
        $admin = $this->admin();
        $this->assertSame(2, $this->actingAs($admin)->getJson('/api/v1/super-admin/billing/payment-notices')->json('pending'));
        $this->actingAs($admin)->postJson("/api/v1/super-admin/billing/payment-notices/{$first['id']}/reject", ['reason' => 'No such transfer'])->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/super-admin/billing/payment-notices/{$second['id']}/approve")->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/super-admin/billing/payment-notices/{$second['id']}/approve")->assertStatus(409);
        $this->assertSame([2000, InvoiceStatus::Open], [$open->refresh()->amount_paid_minor, $open->status]);
        $this->assertDatabaseHas('invoice_payments', ['invoice_id' => $open->id, 'reference' => 'HBL-77813', 'amount_minor' => 2000]);

        $this->staffSession();
        $mine = collect($this->actingAs($this->owner)->getJson('/api/v1/billing/payment-notices')->json('data'))->keyBy('id');
        $this->assertSame(['rejected', 'No such transfer'], [$mine[$first['id']]['status'], $mine[$first['id']]['rejection_reason']]);

        // Staff without billing.manage cannot report payments or change the plan.
        $viewer = User::factory()->create();
        $this->store->users()->attach($viewer, ['role_id' => $this->systemRole($this->store, 'staff')->id, 'status' => 'active']);
        $this->staffSession();
        $this->actingAs($viewer)->postJson("/api/v1/billing/invoices/{$open->public_id}/payment-notices", ['amount_minor' => 1, 'method' => 'cash', 'reference' => 'x', 'paid_on' => '2026-04-07'])->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/v1/billing/plan-change', ['package' => 'premium'])->assertForbidden();
        $this->actingAs($this->owner)->getJson('/api/v1/super-admin/billing/payment-notices')->assertForbidden();
    }
}
