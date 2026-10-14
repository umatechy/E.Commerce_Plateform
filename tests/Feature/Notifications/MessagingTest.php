<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Catalog\Models\Product;
use App\Domain\Events\Jobs\ConsumeOutboxEventJob;
use App\Domain\Events\Models\OutboxEvent;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Notifications\Models\MessagingAccount;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Notifications\Models\NotificationStatus;
use App\Domain\Notifications\Models\NotificationSuppression;
use App\Domain\Orders\Models\Order;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase B50 — gap G10; Module 21 §8–10, §18–19, §23, §29, §31–37, §46, §52,
 * §59; owner decision 3. SMS and WhatsApp through the Test messenger (no
 * network): accounts, order updates, consent, templates, delivery reports,
 * STOP / START, limits, permissions and isolation.
 */
final class MessagingTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutVite();
        $this->store = Store::factory()->create(['status' => 'active', 'name' => 'Rose House']);
        $package = Package::factory()->create();
        foreach (['orders.basic', 'payment.cod', 'shipping.basic', 'products.basic'] as $key) {
            $package->entitlements()->create(['key' => $key, 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        }
        Subscription::factory()->for($this->store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role_id' => $this->systemRole($this->store, 'owner')->id, 'status' => 'active']);
        app(TenantContext::class)->resolveToStore($this->store->id);
        $this->product = Product::factory()->for($this->store)->create(['status' => 'active', 'visibility' => 'public', 'price_minor' => 150000, 'currency' => 'PKR']);
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $inventory = Inventory::factory()->for($this->store)->for($warehouse)->create(['product_id' => $this->product->id]);
        app(InventoryService::class)->setOpeningStock($inventory, 50, 'Init', actorId: $this->owner->id, idempotencyKey: 'open-'.$this->store->id);
    }

    private function asOwner(?User $user = null): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $this->actingAs($user ?? $this->owner);
    }

    private function connect(string $provider, string $channel): MessagingAccount
    {
        $this->asOwner()->putJson("/api/v1/messaging-settings/accounts/{$provider}", ['environment' => 'sandbox', 'credentials' => ['sender' => 'ROSEHOUSE', 'secret' => "{$channel}-signing-secret"]])->assertOk();
        $this->asOwner()->putJson("/api/v1/messaging-settings/channels/{$channel}/enabled", ['enabled' => true])->assertOk();

        return MessagingAccount::query()->where('channel', $channel)->sole();
    }

    private function updates(array $sms, array $whatsapp, int $limit = 500): void
    {
        $this->asOwner()->putJson('/api/v1/messaging-settings', ['sms_events' => $sms, 'whatsapp_events' => $whatsapp, 'daily_limit' => $limit])->assertOk();
    }

    private function order(string $phone = '0300 1234567', bool $whatsapp = false): Order
    {
        $id = $this->asOwner()->postJson('/api/v1/orders', [
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'guest_name' => 'Ayesha', 'guest_email' => 'ayesha'.uniqid().'@example.com',
            'guest_phone' => $phone, 'payment_method' => 'cod', 'source' => 'admin', 'idempotency_key' => 'o-'.uniqid(),
        ])->assertCreated()->json('data.id');
        $order = Order::query()->where('public_id', $id)->sole();
        if ($whatsapp) {
            $order->forceFill(['whatsapp_opt_in' => true])->save(); // as the storefront checkbox does
        }

        return $order;
    }

    private function deliverEvents(): void
    {
        OutboxEvent::query()->withoutTenantScope()->where('status', 'pending')->orderBy('id')->pluck('id')
            ->each(fn (int $id) => app()->call([new ConsumeOutboxEventJob($id), 'handle']));
        app(TenantContext::class)->resolveToStore($this->store->id);
    }

    /** @return \Illuminate\Support\Collection<int, NotificationMessage> */
    private function messages(NotificationChannel $channel): \Illuminate\Support\Collection
    {
        return NotificationMessage::query()->withoutGlobalScopes()->where('store_id', $this->store->id)->where('channel', $channel)->orderBy('id')->get();
    }

    private function webhook(MessagingAccount $account, array $events, ?string $signature = null): \Illuminate\Testing\TestResponse
    {
        $raw = (string) json_encode(['events' => $events]);

        return $this->call('POST', "/api/v1/messaging-webhooks/{$account->webhook_token}", [], [], [], [
            'HTTP_X-Test-Messenger-Signature' => $signature ?? hash_hmac('sha256', $raw, "{$account->channel}-signing-secret"), 'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    public function test_a_store_connects_sms_and_whatsapp_and_secrets_never_come_back(): void
    {
        $this->connect('test_sms', 'sms');
        $this->connect('test_whatsapp', 'whatsapp');
        $settings = $this->asOwner()->getJson('/api/v1/messaging-settings')->assertOk();
        $this->assertStringNotContainsString('signing-secret', $settings->getContent());
        $this->assertStringNotContainsString('signing-secret', (string) DB::table('messaging_accounts')->pluck('credentials')->implode(' '));
        $whatsapp = collect($settings->json('data.channels.whatsapp.providers'))->firstWhere('provider', 'test_whatsapp');
        $this->assertEquals(['sender' => 'RO••••SE', 'secret' => 'set'], $whatsapp['account']['credential_hints']);
        $this->assertStringContainsString('/api/v1/messaging-webhooks/', $whatsapp['account']['webhook_url']);
        $settings->assertJsonPath('data.events.0.whatsapp_body', 'Hi {{1}}, thank you for your order {{3}} at {{2}}. Total: {{4}}.');

        // Twilio's real messages wait for Umar Techy (owner decision 3); Meta's do not.
        $this->asOwner()->putJson('/api/v1/messaging-settings/accounts/twilio', ['environment' => 'production', 'credentials' => ['account_sid' => 'AC'.str_repeat('a', 32), 'auth_token' => 'x', 'sender' => '+15005550006']])->assertStatus(422)->assertJsonValidationErrors('environment');
        $this->asOwner()->putJson('/api/v1/messaging-settings/accounts/meta_whatsapp', ['environment' => 'production', 'credentials' => ['phone_number_id' => '1065403522', 'business_account_id' => '1029384756', 'access_token' => 'EAAJ-token', 'app_secret' => 'meta-app-secret']])->assertOk()
            ->assertJsonPath('data.account.is_enabled', false);
        $this->assertSame('meta_whatsapp', MessagingAccount::query()->where('channel', 'whatsapp')->sole()->provider, 'one account per channel');
        // The generic settings API cannot go round the Messages page.
        $this->asOwner()->putJson('/api/v1/store/settings/messaging.daily_limit', ['value' => 1])->assertForbidden()->assertJsonPath('code', 'managed_on_messages_page');
    }

    public function test_an_order_confirmation_goes_by_sms_once_and_a_delivery_report_marks_it_delivered(): void
    {
        $account = $this->connect('test_sms', 'sms');
        $this->updates(['order_confirmed'], []);
        $order = $this->order();
        $this->deliverEvents();
        $this->deliverEvents();

        $sms = $this->messages(NotificationChannel::Sms)->sole();
        $this->assertSame('+923001234567', $sms->destination);
        $this->assertSame(NotificationStatus::Sent, $sms->status);
        $this->assertStringContainsString("order {$order->order_number} at Rose House", $sms->body);
        $providerId = (string) $sms->attempts()->sole()->provider_message_id;
        $this->assertStringStartsWith('TM-', $providerId);

        $this->webhook($account, [['type' => 'status', 'id' => $providerId, 'status' => 'delivered']], 'forged')->assertForbidden();
        $this->webhook($account, [['type' => 'status', 'id' => $providerId, 'status' => 'delivered']])->assertOk()->assertJsonPath('applied', 1);
        $this->webhook($account, [['type' => 'status', 'id' => $providerId, 'status' => 'delivered']])->assertOk()->assertJsonPath('ignored', 1);
        $this->assertSame(NotificationStatus::Delivered, $sms->fresh()->status);
    }

    public function test_whatsapp_needs_the_customers_yes_and_an_approved_template_and_sends_its_values_in_order(): void
    {
        $this->connect('test_whatsapp', 'whatsapp');
        $this->updates([], ['order_confirmed']);

        $this->order(whatsapp: false);
        $this->deliverEvents();
        $this->assertCount(0, $this->messages(NotificationChannel::WhatsApp), 'no yes, no WhatsApp');

        $this->order(whatsapp: true);
        $this->deliverEvents();
        $first = $this->messages(NotificationChannel::WhatsApp)->sole();
        $this->assertSame(NotificationStatus::Failed, $first->status);
        $this->assertSame('template_missing', $first->attempts()->sole()->failure_code);

        $this->asOwner()->putJson('/api/v1/messaging-settings/templates/order_confirmed', ['template_name' => 'order_confirmed_v1', 'language_code' => 'en'])->assertOk()->assertJsonPath('data.status', 'approved');
        $order = $this->order(whatsapp: true);
        $this->deliverEvents();
        $second = $this->messages(NotificationChannel::WhatsApp)->last();
        $this->assertSame(NotificationStatus::Sent, $second->status);
        $this->assertSame(['Ayesha', 'Rose House', $order->order_number, $second->provider_parameters[3]], $second->provider_parameters);
        $this->asOwner()->putJson('/api/v1/messaging-settings/templates/order_confirmed', ['template_name' => 'Order Confirmed', 'language_code' => 'en'])->assertStatus(422);
    }

    public function test_stop_ends_messages_to_that_number_and_start_allows_them_again(): void
    {
        $account = $this->connect('test_sms', 'sms');
        $this->updates(['order_confirmed', 'order_cancelled'], []);
        $this->webhook($account, [['type' => 'inbound', 'key' => 'k1', 'from' => '+923001234567', 'text' => ' stop ']])->assertOk()->assertJsonPath('applied', 1);
        $this->assertTrue(NotificationSuppression::query()->where('destination', '+923001234567')->where('channel', NotificationChannel::Sms)->exists());

        $this->order();
        $this->deliverEvents();
        $this->assertSame('opted_out', $this->messages(NotificationChannel::Sms)->sole()->attempts()->sole()->failure_code);

        $this->webhook($account, [['type' => 'inbound', 'key' => 'k2', 'from' => '+923001234567', 'text' => 'START']])->assertOk();
        $this->order();
        $this->deliverEvents();
        $this->assertSame(NotificationStatus::Sent, $this->messages(NotificationChannel::Sms)->last()->status);
    }

    public function test_bad_numbers_busy_providers_silence_and_the_daily_limit_are_handled_without_duplicates(): void
    {
        $this->connect('test_sms', 'sms');
        $this->updates(['order_confirmed'], [], limit: 2);

        $this->order('12345');
        $this->deliverEvents();
        $this->assertCount(0, $this->messages(NotificationChannel::Sms), 'an unreadable number gets no message');

        $this->order('03001110002');
        $this->order('03001110004');
        $this->deliverEvents();
        [$bad, $silent] = $this->messages(NotificationChannel::Sms)->all();
        $this->assertSame(['invalid_number', 'no_answer'], [$bad->attempts()->sole()->failure_code, $silent->attempts()->sole()->failure_code]);
        $this->assertSame([NotificationStatus::Failed, NotificationStatus::Failed], [$bad->status, $silent->status]);

        $this->order('03001234561');
        $this->order('03001234562');
        $this->order('03001234563');
        $this->deliverEvents();
        $codes = $this->messages(NotificationChannel::Sms)->slice(2)->map(fn ($m) => $m->status === NotificationStatus::Sent ? 'sent' : $m->attempts()->sole()->failure_code)->values()->all();
        $this->assertSame(['sent', 'sent', 'daily_limit_reached'], $codes);
    }

    public function test_only_people_allowed_change_accounts_and_one_store_never_touches_anothers_messages(): void
    {
        $account = $this->connect('test_sms', 'sms');
        $manager = $this->member(['notifications.view', 'notifications.manage']);
        $this->asOwner($manager)->getJson('/api/v1/messaging-settings')->assertOk()->assertJsonPath('data.can_configure_providers', false)
            ->assertJsonPath('data.channels.sms.providers.1.account.webhook_url', null);
        $this->asOwner($manager)->putJson('/api/v1/messaging-settings/accounts/test_sms', ['environment' => 'sandbox', 'credentials' => ['sender' => 'X', 'secret' => 'y']])->assertForbidden();
        $this->asOwner($manager)->putJson('/api/v1/messaging-settings', ['sms_events' => ['order_confirmed'], 'whatsapp_events' => [], 'daily_limit' => 100])->assertOk();

        // Another store's account cannot change this store's messages, even with a real message id.
        $this->order();
        $this->deliverEvents();
        $sms = $this->messages(NotificationChannel::Sms)->sole();
        $providerId = $sms->attempts()->sole()->provider_message_id;
        $other = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($other->id);
        $foreign = MessagingAccount::query()->create(['channel' => 'sms', 'provider' => 'test_sms', 'environment' => 'sandbox', 'is_enabled' => true, 'credentials' => ['sender' => 'Z', 'secret' => 'sms-signing-secret'], 'webhook_token' => str_repeat('b', 48)]);
        $this->webhook($foreign, [['type' => 'status', 'id' => $providerId, 'status' => 'delivered']])->assertOk()->assertJsonPath('ignored', 1);
        app(TenantContext::class)->resolveToStore($this->store->id);
        $this->assertSame(NotificationStatus::Sent, $sms->fresh()->status);
        $this->assertNotNull($account->id);
    }

    public function test_meta_verifies_its_webhook_with_the_accounts_verify_token(): void
    {
        $this->asOwner()->putJson('/api/v1/messaging-settings/accounts/meta_whatsapp', ['environment' => 'production', 'credentials' => ['phone_number_id' => '1065403522', 'business_account_id' => '1029384756', 'access_token' => 'EAAJ-token', 'app_secret' => 'meta-app-secret']])->assertOk();
        $account = MessagingAccount::query()->sole();
        $this->get("/api/v1/messaging-webhooks/{$account->webhook_token}?hub.mode=subscribe&hub.verify_token={$account->webhook_token}&hub.challenge=8231")->assertOk()->assertSee('8231');
        $this->get("/api/v1/messaging-webhooks/{$account->webhook_token}?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=8231")->assertForbidden();
        $this->get('/api/v1/messaging-webhooks/'.str_repeat('z', 48).'?hub.mode=subscribe')->assertNotFound();
    }

    /** @param list<string> $permissions */
    private function member(array $permissions): User
    {
        $role = Role::factory()->for($this->store)->create();
        foreach ($permissions as $key) {
            $role->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['key' => $key], ['group' => 'notifications'])->id]);
        }
        $user = User::factory()->create();
        $this->store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }
}
