<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domain\Cart\Models\WishlistItem;
use App\Domain\Catalog\Models\Product;
use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase B22 — Module 32 data-subject requests: export (right of access)
 * and erasure (right to be forgotten) are tenant-isolated, permission-
 * gated, audited, and erasure anonymizes without destroying the
 * financial record.
 */
final class CustomerPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function owner(Store $store): User
    {
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);

        return $user;
    }

    /** @param list<string> $permissionKeys */
    private function staffWith(Store $store, array $permissionKeys): User
    {
        $role = Role::factory()->for($store)->create(['slug' => 'custom-'.uniqid()]);

        foreach ($permissionKeys as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['group' => 'compliance', 'description' => 'x']);
            DB::table('permission_role')->insert(['role_id' => $role->id, 'permission_id' => $permission->id]);
        }

        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    private function customerWithHistory(Store $store, OrderStatus $orderStatus = OrderStatus::Completed): Customer
    {
        $customer = Customer::factory()->for($store)->create([
            'name' => 'Jane Shopper',
            'email' => 'jane@example.com',
            'phone' => '+15550001111',
            'password' => Hash::make('customer-pass-99'),
            'marketing_email_opt_in' => true,
        ]);

        $address = ['name' => 'Jane Shopper', 'line1' => '1 Main St', 'city' => 'Springfield', 'country' => 'US'];
        Order::factory()->create([
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'guest_name' => 'Jane Shopper',
            'guest_email' => 'jane@example.com',
            'status' => $orderStatus,
            'billing_address_snapshot' => $address,
            'shipping_address_snapshot' => $address,
            'notes' => 'Leave at the back door',
        ]);

        NotificationMessage::factory()->create([
            'store_id' => $store->id,
            'recipient_id' => $customer->id,
            'destination' => 'jane@example.com',
            'subject' => 'Your order shipped, Jane',
        ]);

        WishlistItem::query()->create([
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'product_id' => Product::factory()->create(['store_id' => $store->id])->id,
        ]);

        $customer->createToken('t');

        return $customer;
    }

    public function test_the_owner_can_export_a_customers_personal_data_and_the_export_is_audited(): void
    {
        $store = Store::factory()->create();
        $owner = $this->owner($store);
        $customer = $this->customerWithHistory($store);

        $response = $this->actingAs($owner)->getJson("/api/v1/customers/{$customer->id}/personal-data");

        $response->assertOk()
            ->assertJsonPath('data.customer.email', 'jane@example.com')
            ->assertJsonPath('data.customer.has_account', true)
            ->assertJsonPath('data.orders.0.shipping_address.line1', '1 Main St')
            ->assertJsonCount(1, 'data.wishlist')
            ->assertJsonCount(1, 'data.notifications');

        $entry = AuditLog::query()->where('action', 'privacy.customer_data_exported')->sole();
        $this->assertSame($store->id, $entry->store_id);
        $this->assertSame($customer->public_id, $entry->subject_public_id);
        // The audit entry records THAT data was exported, never the data itself.
        $this->assertStringNotContainsString('Main St', $entry->getRawOriginal('context'));
    }

    public function test_erasure_anonymizes_the_customer_and_keeps_the_financial_record(): void
    {
        $store = Store::factory()->create();
        $owner = $this->owner($store);
        $customer = $this->customerWithHistory($store);
        $order = Order::query()->withoutTenantScope()->where('customer_id', $customer->id)->sole();

        $response = $this->actingAs($owner)->postJson("/api/v1/customers/{$customer->id}/erase", [
            'reason' => 'Customer request by email, ticket #4411',
            'confirm_email' => 'JANE@example.com',
        ]);

        $response->assertOk()->assertJsonPath('data', [
            'orders_anonymized' => 1,
            'notifications_anonymized' => 1,
            'wishlist_items_removed' => 1,
            'tokens_revoked' => 1,
            'notes_removed' => 0,
            'addresses_removed' => 0,
            'returns_anonymized' => 0,
            'reviews_removed' => 0, // owner decision 15
        ]);

        $customer->refresh();
        $this->assertSame('Erased customer', $customer->name);
        $this->assertSame("erased+{$customer->public_id}@erased.invalid", $customer->email);
        $this->assertNull($customer->phone);
        $this->assertNull($customer->password);
        $this->assertFalse((bool) $customer->marketing_email_opt_in);
        $this->assertNotNull($customer->erased_at);

        $order->refresh();
        $this->assertSame(1000, $order->grand_total_minor); // the financial record is untouched
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertNull($order->guest_name);
        $this->assertNull($order->guest_email);
        $this->assertNull($order->notes);
        $this->assertSame(['country' => 'US', 'redacted' => true], $order->shipping_address_snapshot);

        $message = NotificationMessage::query()->withoutTenantScope()->where('recipient_id', $customer->id)->sole();
        $this->assertSame('erased', $message->destination);
        $this->assertNull($message->subject);

        $this->assertSame(0, WishlistItem::query()->withoutTenantScope()->where('customer_id', $customer->id)->count());
        $this->assertSame(0, $customer->tokens()->count());

        $entry = AuditLog::query()->where('action', 'privacy.customer_erased')->sole();
        $this->assertSame($owner->public_id, $entry->actor_public_id);
        $this->assertSame('Customer request by email, ticket #4411', $entry->contextData()['reason']);
        $this->assertStringNotContainsString('jane@example.com', $entry->getRawOriginal('context'));
    }

    public function test_an_erased_customer_can_no_longer_sign_in(): void
    {
        $store = Store::factory()->create();
        $customer = $this->customerWithHistory($store);

        $this->actingAs($this->owner($store))->postJson("/api/v1/customers/{$customer->id}/erase", [
            'reason' => 'Customer request', 'confirm_email' => 'jane@example.com',
        ])->assertOk();

        $this->postJson('/api/v1/customer/login', ['email' => 'jane@example.com', 'password' => 'customer-pass-99'], ['X-Store-Slug' => $store->slug])
            ->assertStatus(422);
    }

    public function test_erasure_is_blocked_while_the_customer_has_open_orders(): void
    {
        $store = Store::factory()->create();
        $customer = $this->customerWithHistory($store, OrderStatus::Shipped);

        $response = $this->actingAs($this->owner($store))->postJson("/api/v1/customers/{$customer->id}/erase", [
            'reason' => 'Customer request', 'confirm_email' => 'jane@example.com',
        ]);

        $response->assertStatus(409)->assertJsonPath('code', 'open_orders')->assertJsonPath('open_orders', 1);
        $this->assertNull($customer->refresh()->erased_at);
        $this->assertSame('jane@example.com', $customer->email);
        $this->assertFalse(AuditLog::query()->where('action', 'privacy.customer_erased')->exists());
    }

    public function test_erasure_requires_a_reason_and_the_matching_email(): void
    {
        $store = Store::factory()->create();
        $owner = $this->owner($store);
        $customer = $this->customerWithHistory($store);

        $this->actingAs($owner)->postJson("/api/v1/customers/{$customer->id}/erase", [])
            ->assertStatus(422)->assertJsonValidationErrors(['reason', 'confirm_email']);

        $this->actingAs($owner)->postJson("/api/v1/customers/{$customer->id}/erase", [
            'reason' => 'Customer request', 'confirm_email' => 'someone-else@example.com',
        ])->assertStatus(422)->assertJsonValidationErrors('confirm_email');

        $this->assertNull($customer->refresh()->erased_at);
    }

    public function test_erasing_twice_is_a_conflict(): void
    {
        $store = Store::factory()->create();
        $owner = $this->owner($store);
        $customer = $this->customerWithHistory($store);
        $payload = ['reason' => 'Customer request', 'confirm_email' => 'jane@example.com'];

        $this->actingAs($owner)->postJson("/api/v1/customers/{$customer->id}/erase", $payload)->assertOk();

        $this->actingAs($owner)->postJson("/api/v1/customers/{$customer->id}/erase", $payload)
            ->assertStatus(409)->assertJsonPath('code', 'already_erased');
    }

    public function test_another_stores_customer_is_not_found(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->owner($storeA);
        $customerB = $this->customerWithHistory($storeB);

        $this->actingAs($ownerA)->getJson("/api/v1/customers/{$customerB->id}/personal-data")->assertNotFound();
        $this->actingAs($ownerA)->postJson("/api/v1/customers/{$customerB->id}/erase", [
            'reason' => 'x', 'confirm_email' => 'jane@example.com',
        ])->assertNotFound();

        $this->assertNull($customerB->refresh()->erased_at);
    }

    public function test_privacy_actions_require_the_privacy_manage_permission(): void
    {
        $store = Store::factory()->create();
        $customer = $this->customerWithHistory($store);
        $manager = User::factory()->create();
        $store->users()->attach($manager, ['role_id' => $this->systemRole($store, 'manager')->id, 'status' => 'active']);

        $this->actingAs($manager)->getJson("/api/v1/customers/{$customer->id}/personal-data")->assertForbidden();
        $this->actingAs($this->staffWith($store, ['audit.view']))->postJson("/api/v1/customers/{$customer->id}/erase", [
            'reason' => 'x', 'confirm_email' => 'jane@example.com',
        ])->assertForbidden();

        $this->actingAs($this->staffWith($store, ['privacy.manage']))
            ->getJson("/api/v1/customers/{$customer->id}/personal-data")
            ->assertOk();
    }

    public function test_a_customer_can_export_their_own_data_but_not_reach_the_staff_routes(): void
    {
        $store = Store::factory()->create();
        $customer = $this->customerWithHistory($store);
        $token = $customer->createToken('self')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$token}", 'X-Store-Slug' => $store->slug];

        $this->getJson('/api/v1/customer/personal-data', $headers)
            ->assertOk()
            ->assertJsonPath('data.customer.id', $customer->public_id)
            ->assertJsonCount(1, 'data.orders');

        $entry = AuditLog::query()->where('action', 'privacy.customer_data_exported')->sole();
        $this->assertSame('customer', $entry->actor_type->value);
        $this->assertSame('customer', $entry->contextData()['requested_by']);

        $this->getJson("/api/v1/customers/{$customer->id}/personal-data", $headers)->assertUnauthorized();
    }
}
