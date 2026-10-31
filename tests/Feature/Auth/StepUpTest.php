<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase B29 (gap G2) — step-up authentication: a sensitive action needs
 * a recent proof of identity (Module 30 §6, Module 32 §65.5; SRS SA-004).
 */
final class StepUpTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();

        config(['security.step_up.enabled' => true]);
        $this->withHeader('Referer', 'http://localhost'); // a browser session (ADR-002 Surface A)
    }

    private function staff(): User
    {
        return User::factory()->create(['password' => self::PASSWORD, 'platform_role' => 'support_agent']);
    }

    private function impersonate(Store $store): \Illuminate\Testing\TestResponse
    {
        return $this->getJson("/api/v1/super-admin/stores/{$store->id}/impersonate?reason=Support+request+4411");
    }

    public function test_impersonation_needs_a_recent_reauthentication(): void
    {
        $store = Store::factory()->create();

        // Signed in some other way, long ago: no recent proof of identity.
        $this->actingAs($this->staff());
        $this->impersonate($store)->assertStatus(403)->assertJsonPath('code', 'step_up_required');
        // Refused before the impersonation began: nothing about it in the audit trail.
        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'super_admin.%impersonat%')->count());

        $this->postJson('/api/v1/auth/step-up', ['password' => 'wrong'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->impersonate($store)->assertStatus(403);
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.password_confirmation.failed')->count());

        $this->postJson('/api/v1/auth/step-up', ['password' => self::PASSWORD])->assertOk()->assertJsonPath('data.valid_for_minutes', 15);
        $this->impersonate($store)->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.step_up.passed')->count());
    }

    public function test_a_step_up_expires(): void
    {
        $store = Store::factory()->create();
        $this->actingAs($this->staff());

        $this->postJson('/api/v1/auth/step-up', ['password' => self::PASSWORD])->assertOk();
        $this->impersonate($store)->assertOk();

        $this->travel(16)->minutes();
        $this->impersonate($store)->assertStatus(403)->assertJsonPath('code', 'step_up_required');
    }

    public function test_a_fresh_sign_in_counts_as_a_step_up(): void
    {
        $store = Store::factory()->create();
        $staff = $this->staff();

        $this->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => self::PASSWORD])->assertOk();
        $this->impersonate($store)->assertOk();
    }

    public function test_a_request_without_a_session_can_never_pass(): void
    {
        $store = Store::factory()->create();
        $this->withHeader('Referer', 'https://elsewhere.example'); // not the admin app: no session

        $this->actingAs($this->staff());
        $this->postJson('/api/v1/auth/step-up', ['password' => self::PASSWORD])->assertStatus(409)->assertJsonPath('code', 'session_required');
        $this->impersonate($store)->assertStatus(403)->assertJsonPath('code', 'step_up_required');
    }

    public function test_an_ordinary_read_needs_no_step_up(): void
    {
        $this->actingAs($this->staff())->getJson('/api/v1/super-admin/stores')->assertOk();
    }

    public function test_the_sensitive_routes_carry_the_step_up_check(): void
    {
        $guarded = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('step_up', $route->gatherMiddleware(), true))
            ->map(fn ($route) => $route->methods()[0].' '.$route->uri())
            ->sort()->values()->all();

        // Module 30 §6: impersonate, restore backup, change billing, change
        // platform pricing, change configuration, suspend.
        $this->assertSame([
            // Phase B48: where a store's money goes (merchant accounts, bank details), and Umar Techy's
            // own accounts and which providers may take real money.
            'DELETE api/v1/apps/{slug}', // Phase B56: removing an app (it had the store's data)
            'DELETE api/v1/courier-settings/couriers/{provider}', // Phase B49: courier accounts
            'DELETE api/v1/messaging-settings/channels/{channel}', // Phase B50: SMS and WhatsApp accounts
            'DELETE api/v1/payment-settings/providers/{provider}',
            'DELETE api/v1/super-admin/messaging/channels/{channel}',
            'DELETE api/v1/super-admin/payment-providers/{provider}/account',
            // Phase B45 follow-up: deleting a starter template saved from a store.
            'DELETE api/v1/super-admin/starter-templates/{key}',
            // Phase S1: removing a store request (its contact details).
            'DELETE api/v1/super-admin/store-requests/{storeRequest}',
            'GET api/v1/super-admin/stores/{store}/impersonate',
            'PATCH api/v1/super-admin/billing/prices/{price}',
            // Phase B57: paying affiliates — seeing where to send money, recording it, changing balances and accounts.
            'POST api/v1/affiliates/payouts/{payout}/paid',
            'POST api/v1/affiliates/payouts/{payout}/pay-to',
            'POST api/v1/affiliates/{affiliate}/adjustments',
            'POST api/v1/affiliates/{affiliate}/payouts',
            // Phase B56: giving an app access to the store's data, connecting it again, widening it.
            'POST api/v1/apps/{slug}/connect',
            'POST api/v1/apps/{slug}/install',
            'POST api/v1/apps/{slug}/update',
            'POST api/v1/backups/{backup}/restore-request',
            // Phase B32: erasing a customer's personal data cannot be undone.
            'POST api/v1/customers/{customer}/erase',
            // Phase P2: giving or taking loyalty points by hand (they turn into store credit).
            'POST api/v1/customers/{customer}/loyalty/adjust',
            'POST api/v1/customers/{customer}/merge',
            // Phase B34: giving or taking store credit by hand.
            'POST api/v1/customers/{customer}/store-credit/adjust',
            // Phase B51: closing and reopening a store (the Owner, the store's name typed in full).
            'POST api/v1/store-lifecycle/close',
            'POST api/v1/store-lifecycle/reopen',
            // Phase B47: credit notes and their approval, payment notices.
            'POST api/v1/super-admin/billing/credit-notes/{creditNote}/approve',
            'POST api/v1/super-admin/billing/credit-notes/{creditNote}/reject',
            'POST api/v1/super-admin/billing/invoices/{invoice}/credit-notes',
            'POST api/v1/super-admin/billing/invoices/{invoice}/extend-due-date',
            'POST api/v1/super-admin/billing/invoices/{invoice}/payments',
            'POST api/v1/super-admin/billing/invoices/{invoice}/void',
            'POST api/v1/super-admin/billing/payment-notices/{notice}/approve',
            'POST api/v1/super-admin/billing/payment-notices/{notice}/reject',
            'POST api/v1/super-admin/billing/prices',
            'POST api/v1/super-admin/developer/applications/{application}/suspend',
            // Phase B56: the marketplace kill switch, and a new client secret for an app.
            'POST api/v1/super-admin/marketplace/apps/{app}/resume',
            'POST api/v1/super-admin/marketplace/apps/{app}/secret',
            'POST api/v1/super-admin/marketplace/apps/{app}/suspend',
            'POST api/v1/super-admin/packages',
            // Phase B58: the reseller program — its terms, which stores count, termination, payouts and balances.
            'POST api/v1/super-admin/resellers/attributions/{attribution}/end',
            'POST api/v1/super-admin/resellers/payouts/{payout}/paid',
            'POST api/v1/super-admin/resellers/payouts/{payout}/pay-to',
            'POST api/v1/super-admin/resellers/tiers',
            'POST api/v1/super-admin/resellers/{reseller}/adjustments',
            'POST api/v1/super-admin/resellers/{reseller}/payouts',
            'POST api/v1/super-admin/resellers/{reseller}/stores',
            'POST api/v1/super-admin/resellers/{reseller}/terminate',
            'POST api/v1/super-admin/restore-jobs/{backupRestoreJob}/authorize',
            'POST api/v1/super-admin/settings/revisions/{revisionId}/rollback',
            // Phase B44: creating a store for a customer, and inviting its owner.
            'POST api/v1/super-admin/stores',
            // Phase B51: a store's life — suspend, close, archive, restore, permanent deletion.
            'POST api/v1/super-admin/stores/{store}/lifecycle/archive',
            'POST api/v1/super-admin/stores/{store}/lifecycle/close',
            'POST api/v1/super-admin/stores/{store}/lifecycle/deletion',
            'POST api/v1/super-admin/stores/{store}/lifecycle/deletion/cancel',
            'POST api/v1/super-admin/stores/{store}/lifecycle/deletion/retry',
            'POST api/v1/super-admin/stores/{store}/lifecycle/reactivate',
            'POST api/v1/super-admin/stores/{store}/lifecycle/reopen',
            'POST api/v1/super-admin/stores/{store}/lifecycle/restore',
            'POST api/v1/super-admin/stores/{store}/lifecycle/suspend',
            'POST api/v1/super-admin/stores/{store}/owner-invitation',
            'POST api/v1/super-admin/stores/{store}/starter-template', // Phase B45: save a store's structure as a template
            'POST api/v1/super-admin/users/{user}/deactivate',
            'POST api/v1/super-admin/users/{user}/reactivate',
            'POST api/v1/super-admin/users/{user}/sessions/revoke', // Phase B52: end someone's sessions
            'PUT api/v1/affiliates/{affiliate}/payout-details', // Phase B57
            'PUT api/v1/courier-settings/couriers/{provider}',
            'PUT api/v1/messaging-settings/accounts/{provider}',
            'PUT api/v1/partner/payout-details', // Phase B58: where Umar Techy pays a reseller
            'PUT api/v1/payment-settings/bank-transfer',
            'PUT api/v1/payment-settings/providers/{provider}',
            'PUT api/v1/payment-settings/providers/{provider}/enabled',
            'PUT api/v1/super-admin/messaging/accounts/{provider}',
            'PUT api/v1/super-admin/messaging/alert-template',
            'PUT api/v1/super-admin/messaging/channels/{channel}/enabled',
            'PUT api/v1/super-admin/messaging/providers/{provider}/live',
            'PUT api/v1/super-admin/packages/{package}',
            'PUT api/v1/super-admin/packages/{package}/entitlements',
            'PUT api/v1/super-admin/payment-providers/{provider}/account',
            'PUT api/v1/super-admin/payment-providers/{provider}/account/enabled',
            'PUT api/v1/super-admin/payment-providers/{provider}/live',
            'PUT api/v1/super-admin/resellers/tiers/{tier}', // Phase B58
            'PUT api/v1/super-admin/resellers/{reseller}/payout-details', // Phase B58
            'PUT api/v1/super-admin/security/emergency/{key}', // Phase B52: emergency controls
            'PUT api/v1/super-admin/settings/{key}',
            'PUT api/v1/super-admin/starter-templates/{key}',
            'PUT api/v1/super-admin/stores/{store}/lifecycle/legal-hold',
            'PUT api/v1/super-admin/users/{user}/platform-role', // Phase B52: platform roles
        ], $guarded);
    }
}
