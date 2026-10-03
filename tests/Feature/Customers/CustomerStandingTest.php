<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Customers\Models\CustomerStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CustomerAccount\InteractsWithCustomerAccounts;
use Tests\TestCase;

/**
 * Phase B32 (gap G7, Module 10 §9, §13, §30–31, §47–50): what a block
 * or an archive does, email verification and the joining of guest
 * orders, and customer import and export.
 */
final class CustomerStandingTest extends TestCase
{
    use InteractsWithCustomerAccounts, RefreshDatabase;

    private function owner(Store $store): User
    {
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);

        return $user;
    }

    private function block(Customer $customer): void
    {
        $customer->forceFill(['status' => CustomerStatus::Blocked, 'status_reason' => 'Abuse', 'status_changed_at' => now()])->save();
    }

    public function test_a_blocked_customer_cannot_sign_in_and_their_open_session_stops_working(): void
    {
        $store = $this->openStore();
        $customer = $this->registered($store, ['email' => 'blocked@example.com']);
        $headers = $this->as($customer);
        $this->block($customer);

        // A token issued before the block (the block itself revokes them; this one is checked anyway).
        $this->getJson('/api/v1/customer/profile', $headers)->assertForbidden();

        $this->postJson('/api/v1/customer/login', ['email' => 'blocked@example.com', 'password' => 'correct-horse-99'], ['X-Store-Slug' => $store->slug])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.login.refused')->count());

        // A wrong password is answered as before: the block is not revealed to someone without it.
        $wrong = $this->postJson('/api/v1/customer/login', ['email' => 'blocked@example.com', 'password' => 'not-the-password'], ['X-Store-Slug' => $store->slug]);
        $wrong->assertUnprocessable();
        $this->assertStringNotContainsString('contact the store', (string) $wrong->getContent());
    }

    public function test_an_archived_customer_cannot_sign_in_either_and_gets_no_marketing(): void
    {
        $store = $this->openStore();
        $customer = $this->registered($store, ['email' => 'archived@example.com', 'marketing_email_opt_in' => true]);
        $this->assertSame('queued', $this->marketingTo($customer, 'm1'));

        $customer->forceFill(['status' => CustomerStatus::Archived])->save();

        $this->postJson('/api/v1/customer/login', ['email' => 'archived@example.com', 'password' => 'correct-horse-99'], ['X-Store-Slug' => $store->slug])->assertUnprocessable();
        $this->assertSame('suppressed', $this->marketingTo($customer->fresh(), 'm2'));
    }

    public function test_a_blocked_address_cannot_register_again_or_check_out_as_a_guest(): void
    {
        $store = $this->openStore();
        $blocked = $this->registered($store, ['email' => 'blocked@example.com']);
        $this->block($blocked);

        $this->postJson('/api/v1/customer/register', [
            'name' => 'Again', 'email' => 'BLOCKED@example.com', 'password' => 'correct-horse-battery-staple', 'password_confirmation' => 'correct-horse-battery-staple',
        ], ['X-Store-Slug' => $store->slug])->assertUnprocessable()->assertJsonValidationErrors('email');

        $add = $this->postJson('/api/v1/cart/items', ['product_id' => $this->product($store)->id, 'quantity' => 1], ['X-Store-Slug' => $store->slug])->assertSuccessful();
        $shipping = \App\Domain\Shipping\Models\ShippingMethod::query()->where('store_id', $store->id)->where('type', 'store_pickup')->value('id');

        $checkout = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod', 'shipping_method_id' => $shipping, 'shipping_address' => ['country' => 'PK'],
            'guest_name' => 'Someone', 'guest_email' => 'Blocked@Example.com', 'idempotency_key' => 'blocked-guest-1',
        ], ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => (string) $add->headers->get('X-Guest-Cart-Token')]);

        $this->assertContains($checkout->status(), [409, 422]);
        $this->assertSame(0, Order::query()->withoutTenantScope()->where('store_id', $store->id)->count());
    }

    public function test_registering_sends_the_confirmation_link_and_confirming_joins_earlier_guest_orders(): void
    {
        $store = $this->openStore();
        $guestOrder = Order::factory()->create(['store_id' => $store->id, 'customer_id' => null, 'guest_email' => 'Amna@Example.com', 'status' => OrderStatus::Completed]);
        $someoneElses = Order::factory()->create(['store_id' => $store->id, 'customer_id' => null, 'guest_email' => 'other@example.com', 'status' => OrderStatus::Completed]);

        $this->postJson('/api/v1/customer/register', [
            'name' => 'Amna', 'email' => 'amna@example.com', 'password' => 'correct-horse-battery-staple', 'password_confirmation' => 'correct-horse-battery-staple',
        ], ['X-Store-Slug' => $store->slug])->assertCreated();

        $customer = Customer::query()->withoutTenantScope()->where('email', 'amna@example.com')->whereNotNull('password')->sole();
        $this->assertSame(1, DB::table('customer_email_verifications')->where('customer_id', $customer->id)->count());
        // Not joined before the address is confirmed.
        $this->assertNull($guestOrder->fresh()->customer_id);

        // The emailed token is random; only its hash is stored. Put a known one in its place.
        DB::table('customer_email_verifications')->where('customer_id', $customer->id)->update(['token_hash' => hash('sha256', str_repeat('a', 64))]);

        $this->postJson('/api/v1/customer/email/verify', ['email' => 'amna@example.com', 'token' => str_repeat('b', 64)], ['X-Store-Slug' => $store->slug])->assertUnprocessable();
        $this->postJson('/api/v1/customer/email/verify', ['email' => 'amna@example.com', 'token' => str_repeat('a', 64)], ['X-Store-Slug' => $store->slug])->assertOk();

        $this->assertNotNull($customer->fresh()->email_verified_at);
        $this->assertSame($customer->id, $guestOrder->fresh()->customer_id);
        $this->assertNull($someoneElses->fresh()->customer_id);

        // One use only.
        $this->postJson('/api/v1/customer/email/verify', ['email' => 'amna@example.com', 'token' => str_repeat('a', 64)], ['X-Store-Slug' => $store->slug])->assertUnprocessable();
    }

    public function test_a_verification_link_of_one_store_does_nothing_in_another(): void
    {
        $store = $this->openStore();
        $other = Store::factory()->create(['status' => 'active']);
        $customer = $this->registered($store, ['email' => 'amna@example.com']);
        DB::table('customer_email_verifications')->insert([
            'store_id' => $store->id, 'customer_id' => $customer->id, 'email' => 'amna@example.com',
            'token_hash' => hash('sha256', str_repeat('c', 64)), 'expires_at' => now()->addDay(), 'created_at' => now(),
        ]);

        $this->postJson('/api/v1/customer/email/verify', ['email' => 'amna@example.com', 'token' => str_repeat('c', 64)], ['X-Store-Slug' => $other->slug])->assertUnprocessable();
        $this->assertNull($customer->fresh()->email_verified_at);
    }

    /** Sends a marketing email to the customer; returns what became of it. */
    private function marketingTo(Customer $customer, string $key): string
    {
        \Illuminate\Support\Facades\Queue::fake();
        $message = app(NotificationService::class)->send(
            \App\Domain\Notifications\Models\NotificationMessageType::Marketing, \App\Domain\Notifications\Models\NotificationChannel::Email,
            \App\Domain\Notifications\Models\RecipientType::Customer, $customer->id, $customer->email, 'Sale', 'Hello', [], $key,
        );

        return (string) $message?->status->value;
    }

    private function product(Store $store): \App\Domain\Catalog\Models\Product
    {
        $product = \App\Domain\Catalog\Models\Product::factory()->for($store)->create(['status' => 'active', 'visibility' => 'public', 'price_minor' => 2000, 'currency' => 'USD']);
        $warehouse = \App\Domain\Inventory\Models\Warehouse::query()->withoutTenantScope()->where('store_id', $store->id)->where('is_default', true)->firstOrFail();
        $inventory = \App\Domain\Inventory\Models\Inventory::factory()->for($store)->for($warehouse)->create(['product_id' => $product->id]);
        app(\App\Domain\Inventory\Services\InventoryService::class)->setOpeningStock($inventory, 10, 'Init', actorId: $this->actorId(), idempotencyKey: 'open-'.$product->id);

        return $product;
    }
}
