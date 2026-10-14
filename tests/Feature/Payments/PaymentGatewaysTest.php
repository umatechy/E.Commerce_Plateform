<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Orders\Models\Order;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Models\PaymentProviderAccount;
use App\Domain\Payments\Models\PaymentReconciliationItem;
use App\Domain\Payments\Models\PaymentStatus;
use App\Domain\Payments\Models\PaymentWebhookEvent;
use App\Domain\Payments\Providers\SimulatorAdapter;
use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase B48 — gap G9, Module 12 §8, §20–37, §41–43, §46–55, §59–62, §75,
 * §81–82; SRS PAY-001…010. Wallet payments end to end with the Test wallet
 * provider (no network): settings, checkout, retry, timeout, mismatch,
 * expiry, notifications, provider refunds, permissions and isolation.
 */
final class PaymentGatewaysTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private Product $product;

    private int $shippingMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->store, $this->owner, $this->product, $this->shippingMethodId] = $this->shop();
    }

    /** @return array{0: Store, 1: User, 2: Product, 3: int} */
    private function shop(): array
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create();
        foreach (['orders.basic', 'payment.cod', 'payment.bank_transfer', 'payment.online', 'shipping.basic'] as $feature) {
            $package->entitlements()->create(['key' => $feature, 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        }
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);

        $product = Product::factory()->for($store)->create(['status' => 'active', 'visibility' => 'public', 'price_minor' => 2000, 'currency' => 'USD']);
        $warehouse = Warehouse::query()->withoutTenantScope()->where('store_id', $store->id)->where('is_default', true)->firstOrFail();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['product_id' => $product->id]);
        app(TenantContext::class)->resolveToStore($store->id);
        app(InventoryService::class)->setOpeningStock($inventory, 50, 'Init', actorId: $this->actorId(), idempotencyKey: 'open-'.$store->id);
        $shipping = (int) ShippingMethod::query()->where('store_id', $store->id)->where('type', 'store_pickup')->value('id');

        return [$store, $owner, $product, $shipping];
    }

    /** @param list<string> $permissions */
    private function member(array $permissions): User
    {
        $role = \App\Domain\Identity\Models\Role::factory()->for($this->store)->create(['slug' => 'r'.uniqid(), 'name' => 'Role '.uniqid()]);
        foreach ($permissions as $key) {
            $role->permissions()->syncWithoutDetaching([\App\Domain\Identity\Models\Permission::query()->firstOrCreate(['key' => $key], ['group' => 'payments'])->id]);
        }
        $user = User::factory()->create();
        $this->store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    private function asStaff(User $user): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $this->actingAs($user);
    }

    private function connectTestWallet(?Store $store = null, ?User $owner = null): void
    {
        $this->asStaff($owner ?? $this->owner)->putJson('/api/v1/payment-settings/providers/simulator', [
            'environment' => 'sandbox', 'credentials' => ['merchant_id' => 'TESTM1', 'secret' => 's3cr3t-signing-value'],
        ])->assertOk();
        $this->asStaff($owner ?? $this->owner)->putJson('/api/v1/payment-settings/providers/simulator/enabled', ['enabled' => true])->assertOk();
    }

    /** @param array<string, mixed> $extra */
    private function checkout(string $method, array $extra = [], ?Store $store = null, ?Product $product = null, int $quantity = 2): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();
        $store ??= $this->store;
        $add = $this->postJson('/api/v1/cart/items', ['product_id' => ($product ?? $this->product)->id, 'quantity' => $quantity], ['X-Store-Slug' => $store->slug]);
        $token = (string) $add->headers->get('X-Guest-Cart-Token');
        $shipping = $store->is($this->store) ? $this->shippingMethodId : (int) ShippingMethod::query()->withoutTenantScope()->where('store_id', $store->id)->where('type', 'store_pickup')->value('id');

        return $this->postJson('/api/v1/checkout', [
            'payment_method' => $method, 'shipping_method_id' => $shipping, 'shipping_address' => ['country' => 'PK'],
            'guest_name' => 'Sana', 'guest_email' => 'sana@example.com', 'idempotency_key' => 'co-'.uniqid(), ...$extra,
        ], ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token]);
    }

    private function payment(string $publicId): Payment
    {
        return Payment::query()->withoutTenantScope()->where('public_id', $publicId)->firstOrFail();
    }

    public function test_an_owner_connects_the_test_wallet_and_credentials_never_come_back(): void
    {
        $this->connectTestWallet();

        $settings = $this->asStaff($this->owner)->getJson('/api/v1/payment-settings')->assertOk();
        $simulator = collect($settings->json('data.providers'))->firstWhere('provider', 'simulator');
        $this->assertEquals(['merchant_id' => 'TE••••M1', 'secret' => 'set'], $simulator['account']['credential_hints']);
        $this->assertTrue($simulator['account']['is_enabled']);
        $this->assertStringNotContainsString('s3cr3t-signing-value', $settings->getContent());
        // Encrypted at rest (Module 12 §72).
        $this->assertStringNotContainsString('s3cr3t-signing-value', (string) DB::table('payment_provider_accounts')->value('credentials'));
        $this->assertContains('test_wallet', $settings->json('data.checkout_preview'));

        // Audited with field names, never values.
        $audit = DB::table('audit_logs')->where('action', 'payments.provider_account_saved')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('s3cr3t', (string) json_encode($audit));

        // A blank secret keeps the stored one.
        $this->asStaff($this->owner)->putJson('/api/v1/payment-settings/providers/simulator', ['environment' => 'sandbox', 'credentials' => ['merchant_id' => 'TESTM2', 'secret' => '']])->assertOk();
        app(TenantContext::class)->resolveToStore($this->store->id);
        $this->assertSame('s3cr3t-signing-value', PaymentProviderAccount::query()->sole()->providerCredentials()->get('secret'));
    }

    public function test_a_shopper_pays_with_a_wallet_and_the_order_becomes_paid(): void
    {
        $this->connectTestWallet();

        $response = $this->checkout('test_wallet', ['payer' => ['mobile' => '0300 1234567']])->assertCreated();
        $response->assertJsonPath('data.online_payment.attempt.state', 'paid');
        $response->assertJsonPath('data.online_payment.attempt.payer_hint', '0300•••4567');
        $this->assertNotEmpty($response->json('data.online_payment.token'));

        $payment = $this->payment($response->json('data.payment.id'));
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame('paid', Order::query()->withoutTenantScope()->find($payment->order_id)->payment_status->value);
        $this->assertSame(4000, (int) $payment->transactions()->withoutGlobalScopes()->sum('amount_minor'));
        // The full wallet number is stored nowhere (Module 12 §95).
        $this->assertDatabaseMissing('payment_attempts', ['payer_hint' => '03001234567']);
        $this->assertStringNotContainsString('03001234567', (string) json_encode(DB::table('payment_attempts')->get()));
    }

    public function test_a_wrong_wallet_number_is_refused_before_any_order_exists(): void
    {
        $this->connectTestWallet();

        $this->checkout('test_wallet', ['payer' => ['mobile' => '12345']])->assertStatus(422)->assertJsonValidationErrors('payer.mobile');
        $this->assertSame(0, Order::query()->withoutTenantScope()->count());
    }

    public function test_a_declined_payment_can_be_retried_with_the_token_and_no_one_else_can(): void
    {
        $this->connectTestWallet();
        $response = $this->checkout('test_wallet', ['payer' => ['mobile' => '03001110002']])->assertCreated();
        $response->assertJsonPath('data.online_payment.attempt.state', 'failed');
        $this->assertStringContainsString('not approved', (string) $response->json('data.online_payment.attempt.message'));
        $id = $response->json('data.payment.id');
        $token = $response->json('data.online_payment.token');
        $headers = ['X-Store-Slug' => $this->store->slug];

        $this->getJson("/api/v1/checkout/payments/{$id}", $headers)->assertNotFound();
        $this->getJson("/api/v1/checkout/payments/{$id}", $headers + ['X-Payment-Token' => 'wrong-token'])->assertNotFound();
        $this->getJson("/api/v1/checkout/payments/{$id}", $headers + ['X-Payment-Token' => $token])->assertOk()
            ->assertJsonPath('data.can_retry', true)->assertJsonPath('data.attempt.state', 'failed');

        $this->postJson("/api/v1/checkout/payments/{$id}/attempts", ['method' => 'test_wallet', 'payer' => ['mobile' => '03001234567']], $headers + ['X-Payment-Token' => $token])
            ->assertCreated()->assertJsonPath('data.attempt.state', 'paid')->assertJsonPath('data.status', 'paid');
        $this->assertSame(2, PaymentAttempt::query()->withoutTenantScope()->count(), 'the failed attempt is kept');

        // Another store's storefront cannot see it, even with the token.
        [$other] = $this->shop();
        $this->getJson("/api/v1/checkout/payments/{$id}", ['X-Store-Slug' => $other->slug, 'X-Payment-Token' => $token])->assertNotFound();
    }

    public function test_no_answer_is_unknown_blocks_a_retry_and_reconciliation_settles_it(): void
    {
        $this->connectTestWallet();
        $response = $this->checkout('test_wallet', ['payer' => ['mobile' => '03001110004']])->assertCreated();
        $response->assertJsonPath('data.online_payment.attempt.state', 'confirming');
        $id = $response->json('data.payment.id');
        $headers = ['X-Store-Slug' => $this->store->slug, 'X-Payment-Token' => $response->json('data.online_payment.token')];

        $this->postJson("/api/v1/checkout/payments/{$id}/attempts", ['method' => 'test_wallet', 'payer' => ['mobile' => '03001234567']], $headers)
            ->assertStatus(409)->assertJsonPath('code', 'attempt_in_progress');
        $this->assertSame(PaymentStatus::RequiresAction, $this->payment($id)->status, 'a timeout is not a failure');

        $this->travel(1)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame(PaymentStatus::Paid, $this->payment($id)->status);
        $this->assertSame(PaymentAttempt::SUCCEEDED, PaymentAttempt::query()->withoutTenantScope()->sole()->status);
    }

    public function test_a_different_amount_is_not_recorded_as_paid_and_is_flagged_for_a_person(): void
    {
        $this->connectTestWallet();
        $response = $this->checkout('test_wallet', ['payer' => ['mobile' => '03001110006']])->assertCreated();
        $response->assertJsonPath('data.online_payment.attempt.state', 'review');
        $payment = $this->payment($response->json('data.payment.id'));
        $this->assertSame(PaymentStatus::RequiresAction, $payment->status);
        $this->assertSame('mismatch', $payment->reconciliation_status);
        $item = PaymentReconciliationItem::query()->withoutTenantScope()->sole();
        $this->assertSame('amount_mismatch', $item->kind);
        $this->assertSame(3999, $item->details['confirmed_minor']);

        $list = $this->asStaff($this->owner)->getJson('/api/v1/payment-reconciliation')->assertOk();
        $list->assertJsonPath('meta.open_count', 1)->assertJsonPath('data.0.kind', 'amount_mismatch');
        $this->asStaff($this->owner)->getJson('/api/v1/payments?attention=1')->assertOk()->assertJsonCount(1, 'data');

        $this->asStaff($this->owner)->postJson("/api/v1/payment-reconciliation/{$item->public_id}/resolve", ['note' => 'Refunded 39.99 in the provider portal; asked the shopper to pay again.'])
            ->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->assertSame('resolved', $payment->refresh()->reconciliation_status);
        $this->assertSame(PaymentAttempt::EXPIRED, PaymentAttempt::query()->withoutTenantScope()->sole()->status);
        $this->asStaff($this->owner)->postJson("/api/v1/payment-reconciliation/{$item->public_id}/resolve", ['note' => 'Again, twice.'])->assertStatus(409);
    }

    public function test_an_unfinished_wallet_order_expires_and_gives_its_stock_back(): void
    {
        $this->connectTestWallet();
        $response = $this->checkout('test_wallet', ['payer' => ['mobile' => '03001110005']])->assertCreated();
        $response->assertJsonPath('data.online_payment.attempt.state', 'waiting');
        $payment = $this->payment($response->json('data.payment.id'));

        $this->travel(16)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(PaymentAttempt::EXPIRED, PaymentAttempt::query()->withoutTenantScope()->sole()->status);
        $this->assertSame(PaymentStatus::Expired, $payment->refresh()->status);
        $order = Order::query()->withoutTenantScope()->find($payment->order_id);
        $this->assertSame('cancelled', $order->status->value);
        $this->assertSame(0, (int) DB::table('stock_reservations')->where('reference_id', $order->id)->where('status', 'active')->count());
    }

    public function test_a_signed_notification_makes_the_engine_ask_and_forged_or_old_ones_change_nothing(): void
    {
        $this->connectTestWallet();
        $response = $this->checkout('test_wallet', ['payer' => ['mobile' => '03001110003']])->assertCreated();
        $response->assertJsonPath('data.online_payment.attempt.state', 'waiting');
        $reference = $response->json('data.online_payment.attempt.reference');
        $payment = $this->payment($response->json('data.payment.id'));
        $notify = function (string $signature, int $time) use ($reference) {
            $raw = json_encode(['reference' => $reference, 'n' => uniqid()]);

            return $this->call('POST', '/api/v1/payment-webhooks/simulator', [], [], [], [
                'HTTP_X-Simulator-Signature' => $signature === 'valid' ? SimulatorAdapter::sign((string) $time, (string) $raw, 's3cr3t-signing-value') : $signature,
                'HTTP_X-Simulator-Timestamp' => (string) $time, 'CONTENT_TYPE' => 'application/json',
            ], (string) $raw);
        };

        $notify('forged', now()->getTimestamp())->assertOk()->assertJsonPath('status', 'failed');
        $notify('valid', now()->subMinutes(10)->getTimestamp())->assertOk()->assertJsonPath('status', 'failed');
        $this->assertSame(PaymentStatus::RequiresAction, $payment->refresh()->status);
        $this->assertSame(['Signature verification failed.', 'Outside the allowed time window.'], PaymentWebhookEvent::query()->orderBy('id')->pluck('failure_reason')->all());

        $notify('valid', now()->getTimestamp())->assertOk()->assertJsonPath('status', 'processed');
        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        $this->assertSame(1, $payment->transactions()->withoutGlobalScopes()->count(), 'paid once');
    }

    public function test_a_refund_goes_through_the_provider_when_it_can_and_never_above_what_was_paid(): void
    {
        $this->connectTestWallet();
        $id = $this->checkout('test_wallet', ['payer' => ['mobile' => '03001234567']])->assertCreated()->json('data.payment.id');

        $this->asStaff($this->owner)->getJson("/api/v1/payments/{$id}")->assertOk()
            ->assertJsonPath('meta.refund_mode', 'provider')->assertJsonPath('meta.provider_label', 'Test wallet')
            ->assertJsonPath('meta.attempts.0.provider_code', 'SIM-PAID');
        $refund = $this->asStaff($this->owner)->postJson("/api/v1/payments/{$id}/refund", ['amount_minor' => 1500, 'reason' => 'One item returned', 'idempotency_key' => 'r-1'])->assertCreated();
        $this->assertStringStartsWith('SIMR-', (string) $refund->json('data.provider_transaction_reference'));
        $this->asStaff($this->owner)->postJson("/api/v1/payments/{$id}/refund", ['amount_minor' => 1500, 'idempotency_key' => 'r-1'])->assertCreated();
        $this->asStaff($this->owner)->postJson("/api/v1/payments/{$id}/refund", ['amount_minor' => 3000, 'idempotency_key' => 'r-2'])->assertStatus(422)->assertJsonPath('code', 'refund_exceeds_refundable_balance');
        $this->assertSame(PaymentStatus::PartiallyRefunded, $this->payment($id)->status);
    }

    public function test_cash_on_delivery_limits_and_bank_details_are_kept_by_the_server(): void
    {
        $this->asStaff($this->owner)->putJson('/api/v1/payment-settings/cod', ['enabled' => true, 'min_order_minor' => 0, 'max_order_minor' => 3000])->assertOk();
        $this->checkout('cod')->assertStatus(422)->assertJsonPath('code', 'payment_method_unavailable')->assertJsonPath('reason', 'above_maximum');
        $this->assertSame(0, Order::query()->withoutTenantScope()->count(), 'nothing is left behind');
        $this->checkout('cod', quantity: 1)->assertCreated();

        // Bank transfer is offered only with the account filled in.
        $this->checkout('bank_transfer', quantity: 1)->assertStatus(422)->assertJsonPath('reason', 'not_available');
        $this->asStaff($this->owner)->putJson('/api/v1/payment-settings/bank-transfer', ['enabled' => true, 'account_title' => 'Acme'])->assertStatus(422)->assertJsonPath('code', 'bank_details_incomplete');
        $this->asStaff($this->owner)->putJson('/api/v1/payment-settings/bank-transfer', ['enabled' => true, 'account_title' => 'Acme Traders', 'bank_name' => 'Meezan Bank', 'account_number' => 'PK36 MEZN 0001 2345 6789 0123'])->assertOk()->assertJsonPath('data.complete', true);
        $this->get('/shop/'.$this->store->slug.'/checkout')->assertOk()
            ->assertInertia(fn ($page) => $page->where('payment_options.1.method', 'bank_transfer')->where('payment_options.1.bank.bank_name', 'Meezan Bank')->where('payment_options.0.max_minor', 3000));
        $this->checkout('bank_transfer', quantity: 1)->assertCreated();

        // Clearing a field takes bank transfer off the checkout again.
        $this->asStaff($this->owner)->putJson('/api/v1/payment-settings/bank-transfer', ['enabled' => false, 'account_title' => '', 'bank_name' => 'Meezan Bank', 'account_number' => 'PK36'])->assertOk()->assertJsonPath('data.complete', false);

        // The generic settings API cannot go round this page.
        $this->asStaff($this->owner)->putJson('/api/v1/store/settings/payments.cod.max_order_minor', ['value' => 0])->assertForbidden()->assertJsonPath('code', 'managed_on_payments_page');
    }

    public function test_only_people_with_provider_config_change_where_the_money_goes(): void
    {
        // As the system roles have them: managers see and record payments; administrators also refund and reconcile.
        $manager = $this->member(['payments.view', 'payments.manage']);
        $admin = $this->member(['payments.view', 'payments.manage', 'payments.refund', 'payments.reconcile']);

        $this->asStaff($manager)->getJson('/api/v1/payment-settings')->assertOk()->assertJsonPath('data.can_configure', false);
        $this->asStaff($manager)->putJson('/api/v1/payment-settings/bank-transfer', ['enabled' => false])->assertForbidden();
        $this->asStaff($admin)->putJson('/api/v1/payment-settings/providers/simulator', ['environment' => 'sandbox', 'credentials' => ['merchant_id' => 'X1', 'secret' => 'y']])->assertForbidden();
        $this->asStaff($manager)->postJson('/api/v1/payment-reconciliation/01HZZZZZZZZZZZZZZZZZZZZZZZ/resolve', ['note' => 'trying it'])->assertForbidden();
        $this->assertSame(0, PaymentProviderAccount::query()->withoutTenantScope()->count());
    }

    public function test_sandbox_accounts_are_never_offered_and_production_needs_umar_techys_go_ahead(): void
    {
        $credentials = ['merchant_id' => 'MC12345', 'password' => 'pw-1', 'integrity_salt' => 'salt-1'];
        $this->asStaff($this->owner)->putJson('/api/v1/payment-settings/providers/jazzcash', ['environment' => 'production', 'credentials' => $credentials])
            ->assertStatus(422)->assertJsonValidationErrors('environment');
        $this->asStaff($this->owner)->putJson('/api/v1/payment-settings/providers/jazzcash', ['environment' => 'sandbox', 'credentials' => $credentials])->assertOk();
        $this->asStaff($this->owner)->putJson('/api/v1/payment-settings/providers/jazzcash/enabled', ['enabled' => true])->assertStatus(422)->assertJsonPath('code', 'sandbox_not_offered');

        $platform = User::factory()->create(['platform_role' => 'super_admin']);
        // Only through the Payment providers page, which also switches live accounts off on closing.
        $this->asStaff($platform)->putJson('/api/v1/super-admin/settings/payments.live_providers', ['value' => ['jazzcash']])->assertForbidden();
        $this->asStaff($platform)->putJson('/api/v1/super-admin/payment-providers/jazzcash/live', ['allowed' => true, 'reason' => 'Checked in the JazzCash sandbox'])->assertOk();

        // Production needs every value again: nothing is carried over from the sandbox.
        $this->asStaff($this->owner)->putJson('/api/v1/payment-settings/providers/jazzcash', ['environment' => 'production', 'credentials' => ['merchant_id' => 'MC99999']])
            ->assertStatus(422)->assertJsonValidationErrors(['credentials.password', 'credentials.integrity_salt']);
        $this->asStaff($this->owner)->putJson('/api/v1/payment-settings/providers/jazzcash', ['environment' => 'production', 'credentials' => ['merchant_id' => 'MC99999', 'password' => 'live-pw', 'integrity_salt' => 'live-salt'], 'max_amount_minor' => 500000])
            ->assertOk()->assertJsonPath('data.account.is_enabled', false);
        $this->asStaff($this->owner)->putJson('/api/v1/payment-settings/providers/jazzcash/enabled', ['enabled' => true])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->get('/shop/'.$this->store->slug.'/checkout')->assertInertia(fn ($page) => $page->where('payment_methods', ['cod', 'jazzcash']));

        // Closing the provider switches every store's live account off at once.
        $this->asStaff($platform)->putJson('/api/v1/super-admin/payment-providers/jazzcash/live', ['allowed' => false, 'reason' => 'Provider incident'])->assertOk()->assertJsonPath('data.store_accounts_switched_off', 1);
        $this->app['auth']->forgetGuards();
        $this->get('/shop/'.$this->store->slug.'/checkout')->assertInertia(fn ($page) => $page->where('payment_methods', ['cod']));
        $this->asStaff($this->owner)->putJson('/api/v1/payment-settings/providers/jazzcash/enabled', ['enabled' => true])->assertStatus(422)->assertJsonPath('code', 'not_live_yet');
    }

    public function test_one_stores_payment_data_is_never_another_stores(): void
    {
        $this->connectTestWallet();
        $response = $this->checkout('test_wallet', ['payer' => ['mobile' => '03001110005']])->assertCreated();
        $attempt = $response->json('data.online_payment.attempt.id');
        [$other] = $this->shop();
        $otherOwner = User::factory()->create();
        $other->users()->attach($otherOwner, ['role_id' => $this->systemRole($other, 'owner')->id, 'status' => 'active']);

        $this->asStaff($otherOwner)->getJson("/api/v1/payment-attempts/{$attempt}")->assertNotFound();
        $this->asStaff($otherOwner)->getJson("/api/v1/payments/{$response->json('data.payment.id')}")->assertNotFound();
        $this->asStaff($otherOwner)->getJson('/api/v1/payment-settings')->assertOk()
            ->assertJsonPath('data.providers.2.account', null);
        $this->asStaff($this->owner)->getJson("/api/v1/payment-attempts/{$attempt}")->assertOk()->assertJsonPath('data.purpose', 'order');
    }

    public function test_a_store_pays_its_umar_techy_invoice_from_a_wallet(): void
    {
        $platform = User::factory()->create(['platform_role' => 'super_admin']);
        $this->asStaff($platform)->putJson('/api/v1/super-admin/payment-providers/simulator/account', ['environment' => 'sandbox', 'credentials' => ['merchant_id' => 'UTPLATFORM', 'secret' => 'platform-secret']])->assertOk();
        $this->asStaff($platform)->putJson('/api/v1/super-admin/payment-providers/simulator/account/enabled', ['enabled' => true])->assertOk();
        $this->asStaff($platform)->getJson('/api/v1/super-admin/payment-providers')->assertOk()
            ->assertJsonPath('data.providers.2.account.credential_hints.secret', 'set')->assertJsonPath('data.providers.0.live_allowed', false);

        $subscription = Subscription::query()->withoutTenantScope()->where('store_id', $this->store->id)->sole();
        \App\Domain\Billing\Models\PackagePrice::query()->create(['package_id' => $subscription->package_id, 'billing_interval' => 'monthly', 'currency' => 'USD', 'amount_minor' => 3000]);
        $subscription->forceFill(['currency' => 'USD', 'billing_interval' => 'monthly'])->save();
        $number = $this->asStaff($this->owner)->postJson('/api/v1/billing/first-invoice')->assertCreated()->json('data.id');
        $invoice = \App\Domain\Billing\Models\Invoice::query()->withoutTenantScope()->where('public_id', $number)->sole();

        $this->asStaff($this->owner)->getJson("/api/v1/billing/invoices/{$invoice->public_id}/online-payment")->assertOk()
            ->assertJsonPath('data.options.0.provider', 'simulator')->assertJsonPath('data.can_pay', true);
        $this->asStaff($this->owner)->postJson("/api/v1/billing/invoices/{$invoice->public_id}/online-payment", ['provider' => 'simulator', 'payer' => ['mobile' => '03001234567']])
            ->assertCreated()->assertJsonPath('data.state', 'paid');
        $this->assertSame('paid', $invoice->refresh()->status->value);
        $this->assertDatabaseHas('invoice_payments', ['invoice_id' => $invoice->id, 'method' => 'mobile_wallet', 'idempotency_key' => 'attempt:'.PaymentAttempt::query()->withoutTenantScope()->sole()->id]);
        $this->asStaff($this->owner)->postJson("/api/v1/billing/invoices/{$invoice->public_id}/online-payment", ['provider' => 'simulator', 'payer' => ['mobile' => '03001234567']])
            ->assertStatus(409)->assertJsonPath('code', 'invoice_not_open');
    }

    public function test_the_live_list_accepts_only_supported_providers(): void
    {
        $config = app(ConfigService::class);
        $config->set('payments.live_providers', ['easypaisa'], SettingScope::Platform, null);
        $this->assertSame(['easypaisa'], $config->get('payments.live_providers'));
        $config->set('payments.live_providers', [], SettingScope::Platform, null);
        $this->assertSame([], $config->get('payments.live_providers'));
        $this->expectException(\App\Domain\Settings\Exceptions\InvalidSettingValueException::class);
        $config->set('payments.live_providers', ['simulator'], SettingScope::Platform, null);
    }
}
