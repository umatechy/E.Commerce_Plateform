<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PackagePrice;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Database\Seeders\PackageSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase B44 — gap G23, owner decision 13 (Module 03 §5–8, §14, §24–26,
 * §38, §55–58; Module 04 §14): both store creation models through one
 * provisioning service, the sign-up switch, live after payment, the setup
 * checklist and the store's stage.
 */
final class StoreOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-9';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(PackageSeeder::class);
        // Emails stay queued, so the invitation link can be read here: once sent, it is wiped (by design).
        \Illuminate\Support\Facades\Queue::fake();
    }

    private function platform(string $key, mixed $value): void
    {
        DB::table('platform_settings')->updateOrInsert(['key' => $key], ['value' => json_encode([$value]), 'created_at' => now(), 'updated_at' => now()]);
        Cache::flush();
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['platform_role' => 'super_admin']);
    }

    private function signOut(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
    }

    private function register(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/register', [
            'name' => 'Ayesha Khan', 'email' => 'ayesha@example.com', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
            'store_name' => 'Ayesha Fabrics', 'business_category' => 'fashion', ...$overrides,
        ]);
    }

    private function mailedToken(string $email): string
    {
        $mail = NotificationMessage::query()->withoutTenantScope()->where('destination', $email)->latest('id')->firstOrFail();
        preg_match('/#token=([A-Za-z0-9]{64})/', (string) $mail->sealed_body, $m);

        return $m[1];
    }

    public function test_a_customer_signs_up_with_what_the_store_sells_and_gets_the_platform_trial(): void
    {
        $this->platform('platform.trial_days', 30);
        $this->register()->assertCreated();

        $store = Store::query()->where('name', 'Ayesha Fabrics')->firstOrFail();
        $this->assertSame(['fashion', 'self_service', 'pending_setup'], [$store->business_category, $store->created_via, $store->status->value]);
        $this->assertSame(User::query()->where('email', 'ayesha@example.com')->value('id'), $store->created_by_user_id);
        $subscription = Subscription::query()->withoutTenantScope()->where('store_id', $store->id)->firstOrFail();
        $this->assertSame(['trialing', 'basic'], [$subscription->status->value, $subscription->package->code]);
        $this->assertEqualsWithDelta(30, now()->diffInDays($subscription->trial_ends_at), 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'store.provisioned', 'store_id' => $store->id]);

        $this->signOut();
        $this->register(['email' => 'other@example.com', 'business_category' => 'spaceships'])->assertStatus(422)->assertJsonValidationErrors('business_category');
    }

    public function test_umar_techy_can_close_public_sign_up(): void
    {
        $this->platform('platform.self_signup_enabled', false);

        $this->register()->assertStatus(403)->assertJsonPath('code', 'signup_closed');
        $this->assertDatabaseMissing('users', ['email' => 'ayesha@example.com']);
        $this->assertSame(0, Store::query()->count());
        $this->withoutVite()->get('/register')->assertOk()->assertInertia(fn ($page) => $page->where('signupOpen', false));
    }

    public function test_staff_create_a_store_for_a_customer_who_takes_it_over_by_invitation(): void
    {
        $admin = $this->superAdmin();
        $body = ['store_name' => 'Karachi Mobiles', 'business_category' => 'electronics', 'package_code' => 'premium', 'trial_days' => 7, 'owner_email' => 'Bilal@Example.com'];

        $id = $this->actingAs($admin)->postJson('/api/v1/super-admin/stores', $body, ['Idempotency-Key' => 'create-1'])->assertCreated()->json('data.id');
        $again = $this->actingAs($admin)->postJson('/api/v1/super-admin/stores', $body, ['Idempotency-Key' => 'create-1'])->assertCreated()->json('data.id');
        $this->assertSame($id, $again, 'the same request twice makes one store');
        $this->assertSame(1, Store::query()->count());

        $store = Store::query()->findOrFail($id);
        $this->assertSame(['electronics', 'platform', $admin->id], [$store->business_category, $store->created_via, $store->created_by_user_id]);
        $this->assertSame('premium', Subscription::query()->withoutTenantScope()->where('store_id', $id)->firstOrFail()->package->code);

        $row = collect($this->actingAs($admin)->getJson('/api/v1/super-admin/stores?created_via=platform')->assertOk()->json('data.data'))->firstWhere('id', $id);
        $this->assertSame(['awaiting_owner', 'bilal@example.com', 'premium', false], [$row['stage'], $row['owner_email'], $row['package_code'], $row['has_owner']]);
        $this->actingAs($admin)->getJson("/api/v1/super-admin/stores/{$id}")->assertOk()
            ->assertJsonPath('data.stage', 'awaiting_owner')->assertJsonPath('data.owner_invitation.email', 'bilal@example.com');

        // The customer accepts with a new account and becomes the owner.
        $invitation = DB::table('store_invitations')->where('store_id', $id)->latest('id')->value('public_id');
        $token = $this->mailedToken('bilal@example.com');
        $this->signOut();
        $this->postJson("/api/v1/public/invitations/{$invitation}/accept", ['token' => $token, 'name' => 'Bilal Ahmed', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertOk();
        $owner = User::query()->where('email', 'bilal@example.com')->firstOrFail();
        app(TenantContext::class)->resolveToStore($id);
        $this->assertTrue(app(\App\Domain\Identity\Services\StoreTeamService::class)->hasOwner());
        $this->assertSame('owner', DB::table('store_user')->join('roles', 'roles.id', '=', 'store_user.role_id')->where('store_user.store_id', $id)->where('store_user.user_id', $owner->id)->value('roles.slug'));

        $this->actingAs($admin)->getJson("/api/v1/super-admin/stores/{$id}")->assertOk()->assertJsonPath('data.stage', 'onboarding')->assertJsonPath('data.owner_invitation', null);
        $this->actingAs($admin)->postJson("/api/v1/super-admin/stores/{$id}/owner-invitation", ['owner_email' => 'someone@example.com'])->assertStatus(422)->assertJsonPath('code', 'owner_exists');

        // Not for store owners; checked inputs.
        $this->actingAs($owner)->postJson('/api/v1/super-admin/stores', $body)->assertForbidden();
        $this->actingAs($admin)->postJson('/api/v1/super-admin/stores', [...$body, 'package_code' => 'gold'])->assertStatus(422)->assertJsonValidationErrors('package_code');
        $this->actingAs($admin)->postJson('/api/v1/super-admin/stores', [...$body, 'business_category' => 'x'])->assertStatus(422);
    }

    public function test_a_new_owner_invitation_replaces_the_old_link_and_takes_no_team_seat(): void
    {
        $admin = $this->superAdmin();
        $id = $this->actingAs($admin)->postJson('/api/v1/super-admin/stores', ['store_name' => 'Lahore Sweets', 'business_category' => 'food', 'package_code' => 'basic', 'owner_email' => 'old@example.com'])->json('data.id');
        $firstToken = $this->mailedToken('old@example.com');
        $first = DB::table('store_invitations')->where('store_id', $id)->value('public_id');

        $this->actingAs($admin)->postJson("/api/v1/super-admin/stores/{$id}/owner-invitation", ['owner_email' => 'new@example.com'])->assertCreated();
        $this->signOut();
        $this->postJson("/api/v1/public/invitations/{$first}/accept", ['token' => $firstToken, 'name' => 'X', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertNotFound()->assertJsonPath('code', 'invitation_unavailable');
        $this->assertSame(1, DB::table('store_invitations')->where('store_id', $id)->where('status', 'pending')->count());

        // An existing account (owner of another store) accepts by signing in.
        $this->register(['email' => 'new@example.com', 'store_name' => 'My First Shop'])->assertCreated();
        $token = $this->mailedToken('new@example.com');
        $public = DB::table('store_invitations')->where('store_id', $id)->where('status', 'pending')->value('public_id');
        $this->postJson("/api/v1/public/invitations/{$public}/accept", ['token' => $token])->assertOk();
        $this->assertSame(2, DB::table('store_user')->where('user_id', User::query()->where('email', 'new@example.com')->value('id'))->count(), 'one person, two stores (Module 03 §27)');
    }

    public function test_a_store_goes_live_only_after_its_first_payment_when_umar_techy_requires_it(): void
    {
        $this->platform('platform.launch_requires_payment', true);
        $this->register()->assertCreated();
        $owner = User::query()->where('email', 'ayesha@example.com')->firstOrFail();
        $store = Store::query()->firstOrFail();
        app(TenantContext::class)->resolveToStore($store->id);
        Product::factory()->create(['store_id' => $store->id, 'status' => 'active', 'visibility' => 'public', 'price_minor' => 1000]);
        $this->actingAs($owner)->putJson('/api/v1/store/settings/store.contact_email', ['value' => 'shop@example.com'])->assertOk();

        $setup = $this->actingAs($owner)->getJson('/api/v1/storefront/setup')->assertOk()->json('data');
        $this->assertTrue($setup['requires_payment']);
        $this->assertSame(['payment'], $setup['blocking']);
        $this->actingAs($owner)->postJson('/api/v1/storefront/launch')->assertStatus(422)->assertJsonPath('code', 'setup_incomplete');

        // No price yet for the package: said plainly. With a price: the first invoice, once.
        $this->actingAs($owner)->postJson('/api/v1/billing/first-invoice')->assertStatus(422)->assertJsonPath('code', 'no_price');
        PackagePrice::query()->create(['package_id' => Package::query()->where('code', 'basic')->value('id'), 'billing_interval' => 'monthly', 'currency' => Subscription::query()->withoutTenantScope()->where('store_id', $store->id)->value('currency'), 'amount_minor' => 250000]);
        $invoiceId = $this->actingAs($owner)->postJson('/api/v1/billing/first-invoice')->assertCreated()->json('data.id');
        $this->assertSame($invoiceId, $this->actingAs($owner)->postJson('/api/v1/billing/first-invoice')->json('data.id'));

        // Umar Techy records the payment; the store can launch.
        $invoice = Invoice::query()->withoutTenantScope()->where('public_id', $invoiceId)->firstOrFail();
        $this->actingAs($this->superAdmin())->postJson("/api/v1/super-admin/billing/invoices/{$invoice->public_id}/payments", [
            'amount_minor' => $invoice->total_minor, 'method' => 'bank_transfer', 'reference' => 'TRX-1', 'idempotency_key' => 'pay-first-invoice',
        ])->assertCreated();
        $this->actingAs($owner)->postJson('/api/v1/storefront/launch')->assertOk();
        $this->assertNotNull($store->refresh()->activated_at);
    }

    public function test_the_setup_checklist_shows_progress_and_only_steps_the_package_has(): void
    {
        $this->register()->assertCreated();
        $owner = User::query()->where('email', 'ayesha@example.com')->firstOrFail();

        $setup = $this->actingAs($owner)->getJson('/api/v1/storefront/setup')->assertOk()->json('data');
        $keys = array_column($setup['checks'], 'key');
        $this->assertNotContains('payment', $keys, 'payment before launch is off by default');
        $this->assertSame(['business_info', 'products'], $setup['blocking']);
        $this->assertSame(['essentials', 'operations', 'growth'], array_values(array_unique(array_column($setup['checks'], 'group'))));
        $this->assertSame($setup['progress']['total'], count($keys));
        $this->assertSame((int) floor($setup['progress']['done'] * 100 / $setup['progress']['total']), $setup['progress']['percent']);

        // A package without custom domains does not show that step (Module 03 §58).
        $this->assertContains('domain', $keys);
        $store = Store::query()->firstOrFail();
        $package = Subscription::query()->withoutTenantScope()->where('store_id', $store->id)->firstOrFail()->package;
        $package->entitlements()->updateOrCreate(['key' => 'domains.custom_domain'], ['type' => EntitlementType::Feature, 'boolean_value' => false]);
        Cache::flush();
        $this->assertNotContains('domain', array_column($this->actingAs($owner)->getJson('/api/v1/storefront/setup')->json('data.checks'), 'key'));
    }

    public function test_business_information_and_platform_settings_are_checked(): void
    {
        $this->register()->assertCreated();
        $owner = User::query()->where('email', 'ayesha@example.com')->firstOrFail();
        $this->actingAs($owner);

        $this->putJson('/api/v1/store/settings/store.contact_email', ['value' => 'not-an-email'])->assertStatus(422);
        $this->putJson('/api/v1/store/settings/store.contact_phone', ['value' => 'call me'])->assertStatus(422);
        $this->putJson('/api/v1/store/settings/store.contact_phone', ['value' => '+92 300 1234567'])->assertOk();
        $this->putJson('/api/v1/store/settings/store.country', ['value' => 'pk'])->assertOk()->assertJsonPath('data.value', 'PK');
        $this->putJson('/api/v1/store/settings/store.country', ['value' => 'Pakistan'])->assertStatus(422);
        $this->putJson('/api/v1/store/settings/store.legal_name', ['value' => 'Ayesha Fabrics (Pvt) Ltd'])->assertOk();

        $admin = $this->superAdmin();
        $this->actingAs($admin)->putJson('/api/v1/super-admin/settings/platform.trial_package', ['value' => 'gold'])->assertStatus(422);
        $this->actingAs($admin)->putJson('/api/v1/super-admin/settings/platform.trial_package', ['value' => 'business'])->assertOk();
        $this->actingAs($admin)->putJson('/api/v1/super-admin/settings/platform.trial_days', ['value' => 0])->assertStatus(422);
    }
}
