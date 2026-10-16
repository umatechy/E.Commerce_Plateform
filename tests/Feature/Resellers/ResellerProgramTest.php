<?php

declare(strict_types=1);

namespace Tests\Feature\Resellers;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoicePaymentMethod;
use App\Domain\Billing\Models\PackagePrice;
use App\Domain\Billing\Services\InvoiceService;
use App\Domain\Billing\Services\PlanChangeService;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\Package;
use App\Domain\Resellers\Models\Reseller;
use App\Domain\Resellers\Models\ResellerCommission;
use App\Domain\Resellers\Models\ResellerStore;
use App\Domain\Resellers\Models\ResellerTier;
use App\Domain\Resellers\Services\ResellerDirectory;
use App\Domain\Tenancy\Models\Store;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Billing\InteractsWithBilling;
use Tests\TestCase;

/**
 * Phase B58 (gap G20 part 2; Module 28 §3.3, §17, §25–30, §44, §46, §72,
 * §92, §120): the platform reseller program — tiers, onboarding with
 * two-step sign-in, the sign-up link, the partner price on Umar Techy
 * invoices, commissions from paid invoices with refunds taken back,
 * payouts, stores created for clients, termination and isolation.
 */
final class ResellerProgramTest extends TestCase
{
    use InteractsWithBilling, RefreshDatabase;

    private const TERMS = [
        'name' => 'Silver', 'client_discount_percent' => 10, 'initial_commission_percent' => 20, 'recurring_commission_percent' => 10,
        'recurring_months' => 2, 'hold_days' => 10, 'can_provision' => true, 'max_stores' => 1, 'payout_threshold_minor' => 0,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function admin(string $role = 'super_admin'): User
    {
        return User::factory()->create(['platform_role' => $role]);
    }

    /** Another person: a fresh browser, so no session or header is carried over from the previous one. */
    private function as(User $user, bool $mfa = false): static
    {
        $this->flushHeaders();
        $this->withCredentials = false;
        $this->app['session']->driver()->invalidate();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->actingAs($user);

        // A browser of the app (Referer: Sanctum gives it a session) whose sign-in passed the two-step code.
        return $mfa ? $this->withHeader('Referer', 'http://localhost')->withCredentials()->withSession(['auth.mfa_verified_at' => now()->getTimestamp()]) : $this;
    }

    private function tier(array $terms = []): ResellerTier
    {
        $id = $this->as($this->admin())->postJson('/api/v1/super-admin/resellers/tiers', [...self::TERMS, ...$terms])->assertCreated()->json('data.id');

        return ResellerTier::query()->where('public_id', $id)->firstOrFail();
    }

    /** An active reseller whose account has two-step sign-in on. @return array{0: Reseller, 1: User} */
    private function reseller(ResellerTier $tier, string $email = 'partner@agency.example'): array
    {
        [$reseller, $url] = app(ResellerDirectory::class)->invite($this->admin(), $tier, ['business_name' => 'Pixel Agency', 'contact_name' => 'Ayesha Khan', 'email' => $email]);
        $user = User::factory()->create(['email' => $email, 'mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()]);
        app(ResellerDirectory::class)->accept(substr((string) strstr($url, '#token='), 7), $user);

        return [$reseller->refresh(), $user];
    }

    private function pay(Invoice $invoice): void
    {
        app(InvoiceService::class)->recordPayment($invoice, [
            'amount_minor' => $invoice->amountDue(), 'method' => InvoicePaymentMethod::BankTransfer, 'reference' => 'TRX-'.$invoice->id,
            'received_at' => CarbonImmutable::now(), 'idempotency_key' => uniqid('pay-'),
        ], null);
    }

    private function followUp(): void
    {
        $this->artisan('resellers:follow-up')->assertSuccessful();
    }

    // --- Onboarding (§92) ---

    public function test_umar_techy_invites_a_business_that_joins_and_opens_the_portal_only_with_two_step_sign_in(): void
    {
        $tier = $this->tier();
        $this->assertSame([1000, 2000, 1000, 2], [$tier->client_discount_bp, $tier->initial_commission_bp, $tier->recurring_commission_bp, $tier->recurring_months]);
        $admin = $this->admin();
        $invite = $this->as($admin)->postJson('/api/v1/super-admin/resellers', ['business_name' => 'Pixel Agency', 'contact_name' => 'Ayesha Khan', 'email' => 'Ayesha@Pixel.example', 'tier' => $tier->public_id])
            ->assertCreated()->json('data');
        $token = substr((string) strstr($invite['invitation_url'], '#token='), 7);
        $this->assertTrue(DB::table('notification_messages')->where('destination', 'ayesha@pixel.example')->where('body', 'like', '%[hidden]%')->exists(), 'the link is emailed, never stored readable');
        $this->assertNotSame($token, Reseller::query()->value('invitation_token_hash'));

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/partner/invitations/lookup', ['token' => $token])->assertOk()->assertJsonPath('data.business_name', 'Pixel Agency')->assertJsonPath('data.account_exists', false);
        $this->postJson('/api/v1/partner/invitations/lookup', ['token' => str_repeat('x', 48)])->assertNotFound();
        $this->postJson('/api/v1/partner/invitations/register', ['token' => $token, 'name' => 'Ayesha Khan', 'password' => 'Partner-Pass-2026!', 'password_confirmation' => 'Partner-Pass-2026!'])->assertCreated();
        $user = User::query()->where('email', 'ayesha@pixel.example')->sole();
        $this->assertSame(['active', $user->id], [Reseller::query()->value('status'), Reseller::query()->value('user_id')]);
        $this->postJson('/api/v1/partner/invitations/lookup', ['token' => $token])->assertNotFound();

        $this->as($user)->getJson('/api/v1/partner/overview')->assertForbidden()->assertJsonPath('code', 'mfa_enrollment_required');
        $user->forceFill(['mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()])->save();
        $this->as($user)->withHeader('Referer', 'http://localhost')->withCredentials()->getJson('/api/v1/partner/overview')->assertForbidden()->assertJsonPath('code', 'mfa_verification_required');
        $this->as($user, mfa: true)->getJson('/api/v1/partner/overview')->assertOk()
            ->assertJsonPath('data.tier.client_discount_percent', 10)
            ->assertJsonPath('data.signup_link', rtrim((string) config('app.url'), '/').'/register?partner='.$invite['code']);
        $this->as(User::factory()->create(), mfa: true)->getJson('/api/v1/partner/overview')->assertForbidden()->assertJsonPath('code', 'not_a_reseller');

        // An invitation is accepted only by the account of the invited address.
        $second = $this->as($admin)->postJson('/api/v1/super-admin/resellers', ['business_name' => 'Other Co', 'contact_name' => 'Bilal', 'email' => 'bilal@other.example', 'tier' => $tier->public_id])->json('data.invitation_url');
        $this->as($user)->postJson('/api/v1/partner/invitations/accept', ['token' => substr((string) strstr($second, '#token='), 7)])->assertUnprocessable()->assertJsonPath('code', 'email_mismatch');
    }

    public function test_a_business_applies_and_is_approved_with_a_tier_or_declined(): void
    {
        $tier = $this->tier();
        $form = ['business_name' => 'Lahore Web Studio', 'contact_name' => 'Usman', 'email' => 'usman@studio.example', 'accept_terms' => true, 'country' => 'PK'];
        $this->app['auth']->forgetGuards(); // a visitor of the public partner page
        $this->postJson('/api/v1/partner/applications', $form)->assertStatus(202);
        $this->postJson('/api/v1/partner/applications', [...$form, 'contact_name' => 'Usman again'])->assertStatus(202);
        $this->postJson('/api/v1/partner/applications', [...$form, 'email' => 'bot@spam.example', 'fax' => 'x'])->assertUnprocessable();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])->postJson('/api/v1/partner/applications', [...$form, 'email' => 'second@studio.example', 'business_name' => 'Second Studio'])->assertStatus(202);
        $this->assertSame(2, Reseller::query()->where('status', 'pending')->count());

        $admin = $this->admin();
        [$usman, $second] = [Reseller::query()->where('email', 'usman@studio.example')->sole(), Reseller::query()->where('email', 'second@studio.example')->sole()];
        $this->assertNull($usman->reseller_tier_id);
        $this->as($admin)->postJson("/api/v1/super-admin/resellers/{$usman->public_id}/approve", ['tier' => $tier->public_id])->assertOk()->assertJsonPath('data.invitation_url', fn ($url) => str_contains($url, '/partner/join#token='));
        $this->as($admin)->postJson("/api/v1/super-admin/resellers/{$second->public_id}/reject", ['reason' => 'Not a fit'])->assertOk();
        $this->assertSame(['invited', 'rejected'], [$usman->refresh()->status, $second->refresh()->status]);
        $this->assertSame($tier->id, $usman->reseller_tier_id);
        $this->as($admin)->getJson('/api/v1/super-admin/resellers?status=invited')->assertOk()->assertJsonCount(1, 'data');
    }

    // --- Attribution (§17) ---

    public function test_a_client_who_signs_up_through_the_partner_link_counts_for_the_reseller_with_the_terms_of_that_day(): void
    {
        $this->seed(\Database\Seeders\PackageSeeder::class); // the trial package of a sign-up
        [$reseller] = $this->reseller($this->tier());
        $this->app['auth']->forgetGuards();
        $this->app['session']->driver()->invalidate();
        // One browser: the sign-up page remembers the partner in the session the sign-up then uses.
        $page = $this->withoutVite()->get('/register?partner='.strtolower($reseller->code))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/Register')->where('partner', 'Pixel Agency'));
        $this->withCookie((string) config('session.cookie'), (string) $page->getCookie((string) config('session.cookie'))?->getValue());
        $this->withHeader('Referer', 'http://localhost')->withCredentials()->postJson('/api/v1/auth/register', [
            'name' => 'Client One', 'email' => 'client@shop.example', 'password' => 'Client-Pass-2026!', 'password_confirmation' => 'Client-Pass-2026!',
            'store_name' => 'Client Shop', 'business_category' => 'fashion',
        ])->assertCreated();

        $attribution = ResellerStore::query()->sole();
        $this->assertSame(['link', $reseller->id], [$attribution->source, $attribution->reseller_id]);
        $this->assertSame(1000, $attribution->terms['client_discount_bp']);
        $this->assertSame(0, DB::table('store_user')->where('store_id', $attribution->store_id)->whereIn('user_id', [$reseller->user_id])->count(), 'the reseller never becomes a member of the client store');

        // Changing the tier later does not change what that store agreed to.
        $this->as($this->admin())->putJson('/api/v1/super-admin/resellers/tiers/'.$reseller->tier->public_id, [...self::TERMS, 'client_discount_percent' => 25])->assertOk();
        $this->assertSame(1000, $attribution->refresh()->terms['client_discount_bp']);
    }

    // --- Partner price and commissions (§25–26) ---

    public function test_the_client_pays_the_partner_price_and_each_paid_invoice_earns_a_commission_within_the_recurring_period(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-01 09:00:00'));
        [$reseller] = $this->reseller($this->tier());
        [$store, , $subscription] = $this->billedStore(3000, periodEnd: CarbonImmutable::parse('2026-03-15 09:00:00'));
        $this->as($this->admin())->postJson("/api/v1/super-admin/resellers/{$reseller->public_id}/stores", ['store' => (string) $store->id, 'reason' => 'Signed through the agency'])->assertCreated();

        $this->travelTo(CarbonImmutable::parse('2026-03-08 09:00:00'));
        $this->runBilling();
        $first = $this->invoicesOf($subscription)->sole();
        $this->assertSame(2700, $first->subtotal_minor, 'the partner price: 10% off 3000');
        $this->assertStringContainsString('partner price', (string) $first->lines()->value('description'));
        $premium = Package::factory()->create(['name' => 'Premium', 'code' => 'premium']);
        PackagePrice::query()->create(['package_id' => $premium->id, 'billing_interval' => 'monthly', 'currency' => 'USD', 'amount_minor' => 6000]);
        $this->assertSame(5400, app(PlanChangeService::class)->preview($subscription->refresh(), $premium)['to']['price_minor'], 'plan changes use the partner price too');

        $this->pay($first);
        $this->followUp();
        $initial = ResellerCommission::query()->sole();
        $this->assertSame(['initial', 'pending', 2700, 540, 'USD'], [$initial->kind, $initial->status, $initial->base_minor, $initial->amount_minor, $initial->currency]);
        $this->followUp();
        $this->assertSame(1, ResellerCommission::query()->count(), 'one commission per invoice');

        $this->travelTo(CarbonImmutable::parse('2026-03-19 09:00:00'));
        $this->followUp();
        $this->assertSame('approved', $initial->refresh()->status, 'the 10-day hold ended');

        $this->travelTo(CarbonImmutable::parse('2026-04-08 09:00:00'));
        $this->runBilling();
        $this->pay($this->invoicesOf($subscription)->last());
        $this->followUp();
        $this->assertSame(['renewal', 270], [ResellerCommission::query()->latest('id')->value('kind'), (int) ResellerCommission::query()->latest('id')->value('amount_minor')]);

        $this->travelTo(CarbonImmutable::parse('2026-05-08 09:00:00'));
        $this->runBilling();
        $this->pay($this->invoicesOf($subscription)->last());
        $this->followUp();
        $this->assertSame(2, ResellerCommission::query()->count(), 'the period from 15 May is outside the 2 recurring months');
    }

    // --- Payouts (§33–39) ---

    public function test_payouts_need_details_reveal_the_account_under_audit_and_refunds_after_payment_become_adjustments(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-01 09:00:00'));
        [$reseller, $user] = $this->reseller($this->tier(['hold_days' => 0]));
        [$store, , $subscription] = $this->billedStore(3000, periodEnd: CarbonImmutable::parse('2026-03-15 09:00:00'));
        $admin = $this->admin();
        $this->as($admin)->postJson("/api/v1/super-admin/resellers/{$reseller->public_id}/stores", ['store' => $store->slug, 'reason' => 'Agency client'])->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-03-08 09:00:00'));
        $this->runBilling();
        $invoice = $this->invoicesOf($subscription)->sole();
        $this->pay($invoice);
        $this->followUp();
        $this->followUp();
        $this->assertSame('approved', ResellerCommission::query()->value('status'));

        $this->as($admin)->postJson("/api/v1/super-admin/resellers/{$reseller->public_id}/payouts", ['currency' => 'USD'])->assertUnprocessable()->assertJsonPath('code', 'no_payout_details');
        $this->as($user, mfa: true)->putJson('/api/v1/partner/payout-details', ['method' => 'bank_transfer', 'account_title' => 'Pixel Agency', 'account_number' => 'PK36 SCBL 0000 0011 2345 6702', 'bank' => 'Example Bank'])
            ->assertOk()->assertJsonPath('data.account', str_repeat('•', 20).'6702');
        $payout = $this->as($admin)->postJson("/api/v1/super-admin/resellers/{$reseller->public_id}/payouts", ['currency' => 'USD'])->assertCreated()
            ->assertJsonPath('data.amount_minor', 540)->assertJsonPath('data.pay_to.account_number', 'PK36SCBL0000001123456702')->json('data.id');
        $this->as($admin)->postJson("/api/v1/super-admin/resellers/{$reseller->public_id}/payouts", ['currency' => 'USD'])->assertUnprocessable()->assertJsonPath('code', 'payout_open');
        $this->as($admin)->postJson("/api/v1/super-admin/resellers/payouts/{$payout}/pay-to")->assertOk();
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'resellers.payout_details.revealed')->count());
        $this->as($admin)->postJson("/api/v1/super-admin/resellers/payouts/{$payout}/paid", ['reference' => 'IBFT-778899'])->assertOk();
        $this->assertSame('paid', ResellerCommission::query()->value('status'));

        // Half the invoice refunded by a credit note after the payout: taken from the next one.
        $this->as($admin)->postJson("/api/v1/super-admin/billing/invoices/{$invoice->public_id}/credit-notes", ['reason' => 'Outage', 'amount_minor' => 1350, 'settlement' => 'refund', 'refund_method' => 'bank_transfer', 'refund_reference' => 'R-1'])->assertCreated();
        $this->followUp();
        $this->followUp();
        $this->assertSame(-270, (int) DB::table('reseller_adjustments')->sum('amount_minor'));
        $this->as($user, mfa: true)->getJson('/api/v1/partner/overview')->assertOk()
            ->assertJsonPath('data.balances.USD.paid_minor', 540)->assertJsonPath('data.balances.USD.payable_minor', -270);
    }

    // --- Stores created for clients (§28–30, §120) ---

    public function test_a_reseller_creates_a_store_for_a_client_once_per_key_within_its_tier_and_never_owns_it(): void
    {
        $package = Package::factory()->create(['name' => 'Business', 'code' => 'business']);
        PackagePrice::query()->create(['package_id' => $package->id, 'billing_interval' => 'monthly', 'currency' => 'PKR', 'amount_minor' => 500000]);
        [$reseller, $user] = $this->reseller($this->tier());
        $this->as($user, mfa: true)->getJson('/api/v1/partner/packages')->assertOk()
            ->assertJsonPath('data.0.prices.0.public_minor', 500000)->assertJsonPath('data.0.prices.0.partner_minor', 450000);

        $body = ['store_name' => 'Rida Boutique', 'business_category' => 'fashion', 'package_code' => 'business', 'owner_email' => 'rida@client.example', 'starter_template' => false];
        $first = $this->as($user, mfa: true)->postJson('/api/v1/partner/stores', $body, ['Idempotency-Key' => 'create-rida-1'])->assertCreated()->json('data.id');
        $again = $this->as($user, mfa: true)->postJson('/api/v1/partner/stores', $body, ['Idempotency-Key' => 'create-rida-1'])->assertOk()->json('data.id');
        $this->assertSame($first, $again, 'a retry returns the same store');
        $store = Store::query()->where('name', 'Rida Boutique')->sole();
        $this->assertSame(['reseller', $user->id], [$store->created_via, $store->created_by_user_id]);
        $this->assertSame(0, DB::table('store_user')->where('store_id', $store->id)->count(), 'the client becomes the owner by accepting; the reseller never joins');
        $this->assertTrue(DB::table('store_invitations')->where('store_id', $store->id)->where('email', 'rida@client.example')->exists());
        $this->as($user, mfa: true)->getJson('/api/v1/partner/stores')->assertOk()->assertJsonPath('data.0.store', 'Rida Boutique')->assertJsonPath('data.0.owner_joined', false);

        $this->as($user, mfa: true)->postJson('/api/v1/partner/stores', [...$body, 'store_name' => 'Second'], ['Idempotency-Key' => 'create-2'])->assertUnprocessable()->assertJsonPath('code', 'store_limit');
        $this->as($this->admin())->postJson("/api/v1/super-admin/resellers/{$reseller->public_id}/pause", ['reason' => 'Review'])->assertOk();
        $this->as($user, mfa: true)->getJson('/api/v1/partner/overview')->assertOk()->assertJsonPath('data.status', 'paused');
        $this->as($user, mfa: true)->postJson('/api/v1/partner/stores', [...$body, 'store_name' => 'Third'], ['Idempotency-Key' => 'create-3'])->assertUnprocessable()->assertJsonPath('code', 'reseller_not_active');

        [, $other] = $this->reseller($this->tier(['name' => 'Referral only', 'can_provision' => false]), 'other@agency.example');
        $this->as($other, mfa: true)->postJson('/api/v1/partner/stores', $body, ['Idempotency-Key' => 'x-1'])->assertUnprocessable()->assertJsonPath('code', 'provisioning_not_allowed');
        $this->as($other, mfa: true)->getJson('/api/v1/partner/stores')->assertOk()->assertJsonCount(0, 'data');
    }

    // --- Termination and access (§72, §111) ---

    public function test_termination_closes_the_portal_and_stops_new_commissions_while_earned_ones_stay_payable(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-01 09:00:00'));
        [$reseller, $user] = $this->reseller($this->tier(['hold_days' => 0, 'recurring_months' => null]));
        [$store, , $subscription] = $this->billedStore(3000, periodEnd: CarbonImmutable::parse('2026-03-15 09:00:00'));
        $admin = $this->admin();
        $this->as($admin)->postJson("/api/v1/super-admin/resellers/{$reseller->public_id}/stores", ['store' => (string) $store->id, 'reason' => 'Agency client'])->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-03-08 09:00:00'));
        $this->runBilling();
        $this->pay($this->invoicesOf($subscription)->sole());
        $this->followUp();

        $this->as($admin)->postJson("/api/v1/super-admin/resellers/{$reseller->public_id}/terminate", ['reason' => 'Agreement ended'])->assertOk();
        $this->as($user, mfa: true)->getJson('/api/v1/partner/overview')->assertForbidden()->assertJsonPath('code', 'not_a_reseller');

        $this->travelTo(CarbonImmutable::parse('2026-04-08 09:00:00'));
        $this->runBilling();
        $renewal = $this->invoicesOf($subscription)->last();
        $this->assertSame(2700, $renewal->subtotal_minor, 'the client keeps the partner price it signed up for');
        $this->pay($renewal);
        $this->followUp();
        $this->assertSame(1, ResellerCommission::query()->count(), 'nothing earned on invoices after termination');
        $reseller->update(['payout_method' => 'jazzcash', 'payout_details' => ['account_title' => 'Pixel', 'account_number' => '03001234567', 'bank' => null]]);
        $this->as($admin)->postJson("/api/v1/super-admin/resellers/{$reseller->public_id}/payouts", ['currency' => 'USD'])->assertCreated()->assertJsonPath('data.amount_minor', 540);

        // Umar Techy ends the attribution: from the next invoice the store pays the public price.
        $attribution = ResellerStore::query()->sole();
        $this->as($admin)->postJson("/api/v1/super-admin/resellers/attributions/{$attribution->public_id}/end", ['reason' => 'Client asked'])->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-05-08 09:00:00'));
        $this->runBilling();
        $this->assertSame(3000, $this->invoicesOf($subscription)->last()->subtotal_minor);
    }

    public function test_only_platform_staff_with_the_resellers_capability_run_the_program(): void
    {
        $this->tier();
        // Reading the Super Admin pages needs only "view" (Module 30); changing anything needs the capability.
        $this->as($this->admin('support_admin'))->postJson('/api/v1/super-admin/resellers/tiers', [...self::TERMS, 'name' => 'Gold'])->assertForbidden();
        $this->as($this->admin('billing_admin'))->getJson('/api/v1/super-admin/resellers')->assertOk();
        $this->as($this->admin('read_only_auditor'))->postJson('/api/v1/super-admin/resellers/tiers', [...self::TERMS, 'name' => 'Gold'])->assertForbidden();
        [, $user] = $this->reseller(ResellerTier::query()->sole());
        $this->as($user, mfa: true)->getJson('/api/v1/super-admin/resellers')->assertForbidden();
        $this->as($this->admin())->postJson('/api/v1/super-admin/resellers/tiers', [...self::TERMS, 'name' => 'Too much', 'client_discount_percent' => 80])->assertUnprocessable()->assertJsonValidationErrors('client_discount_percent');
    }
}
