<?php

declare(strict_types=1);

namespace Tests\Feature\StoreCredit;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Models\Payment;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\StoreCredit\Models\StoreCreditEntry;
use App\Domain\StoreCredit\Models\StoreCreditEntryType;
use App\Domain\StoreCredit\Services\StoreCreditService;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\Feature\Returns\BuildsReturns;
use Tests\TestCase;

/**
 * Phase B34 — Module 09 §52 "Store Credit Foundation" and Module 12 §13
 * (order total − eligible store credit = remaining payable amount): the
 * ledger, staff adjustments, paying at checkout, cancelling, and refunds
 * to and as store credit.
 */
final class StoreCreditTest extends TestCase
{
    use BuildsReturns, RefreshDatabase;

    private function member(array $attributes = []): Customer
    {
        return Customer::factory()->for($this->store)->create(['password' => Hash::make('correct-horse-99'), ...$attributes]);
    }

    private function give(Customer $customer, int $amountMinor): void
    {
        app(StoreCreditService::class)->credit($customer, 'PKR', $amountMinor, StoreCreditEntryType::Adjustment, 'seed-'.uniqid(), null, 'Test', $this->owner->id);
    }

    private function balance(Customer $customer): int
    {
        return app(StoreCreditService::class)->balance($customer, 'PKR');
    }

    /** The customer puts `$quantity` of the product in their cart and checks out (cash on delivery, store pickup). */
    private function checkout(Customer $customer, int $quantity, bool $useCredit, ?string $key = null): \Illuminate\Testing\TestResponse
    {
        $headers = $this->as($customer);
        $this->postJson('/api/v1/cart/items', ['product_id' => $this->product->id, 'quantity' => $quantity], $headers)->assertSuccessful();
        $pickup = ShippingMethod::query()->where('store_id', $this->store->id)->where('type', 'store_pickup')->value('id');

        return $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod', 'shipping_method_id' => $pickup, 'shipping_address' => ['country' => 'PK'],
            'use_store_credit' => $useCredit, 'idempotency_key' => $key ?? 'co-'.uniqid(),
        ], $this->as($customer));
    }

    public function test_staff_with_the_permission_give_and_take_credit_with_a_reason_and_it_never_goes_negative(): void
    {
        $customer = $this->member();
        $url = "/api/v1/customers/{$customer->public_id}/store-credit";
        $viewer = $this->staffWith(['customers.view']);

        $this->actingAs($this->owner)->postJson("{$url}/adjust", ['amount_minor' => 50000, 'idempotency_key' => 'adj-00001'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->actingAs($this->owner)->postJson("{$url}/adjust", ['amount_minor' => 0, 'note' => 'x', 'idempotency_key' => 'adj-00002'])->assertUnprocessable();

        $body = ['amount_minor' => 50000, 'note' => 'Goodwill for a late delivery', 'idempotency_key' => 'adj-00003'];
        $this->actingAs($this->owner)->postJson("{$url}/adjust", $body)->assertCreated()->assertJsonPath('data.balance_minor', 50000)->assertJsonPath('data.currency', 'PKR');
        // The same request again gives nothing more.
        $this->actingAs($this->owner)->postJson("{$url}/adjust", $body)->assertCreated()->assertJsonPath('data.balance_minor', 50000);
        $this->assertSame(1, AuditLog::query()->where('action', 'store_credit.adjusted')->count());

        // Taking back more than there is: refused, with the balance untouched.
        $this->actingAs($this->owner)->postJson("{$url}/adjust", ['amount_minor' => -50001, 'note' => 'Mistake', 'idempotency_key' => 'adj-00004'])
            ->assertUnprocessable()->assertJsonPath('code', 'insufficient_store_credit');
        $this->actingAs($this->owner)->postJson("{$url}/adjust", ['amount_minor' => -20000, 'note' => 'Given twice by mistake', 'idempotency_key' => 'adj-00005'])
            ->assertCreated()->assertJsonPath('data.balance_minor', 30000)->assertJsonPath('data.entries.0.amount_minor', -20000)->assertJsonPath('data.entries.0.balance_after_minor', 30000);

        // Seeing is part of seeing the customer; changing needs its own permission.
        $this->app['auth']->forgetGuards();
        $this->actingAs($viewer)->getJson($url)->assertOk()->assertJsonPath('data.balance_minor', 30000)->assertJsonPath('data.can_adjust', false);
        $this->actingAs($viewer)->postJson("{$url}/adjust", ['amount_minor' => 100, 'note' => 'x', 'idempotency_key' => 'adj-00006'])->assertForbidden();

        // Another store.
        $outsider = User::factory()->create();
        $elsewhere = Store::factory()->create();
        $elsewhere->users()->attach($outsider, ['role_id' => $this->systemRole($elsewhere, 'owner')->id, 'status' => 'active']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($outsider)->getJson($url)->assertNotFound();
        $this->actingAs($outsider)->postJson("{$url}/adjust", ['amount_minor' => 100, 'note' => 'x', 'idempotency_key' => 'adj-00007'])->assertNotFound();
        $this->assertSame(30000, $this->balance($customer));
    }

    public function test_the_ledger_cannot_be_edited_or_deleted(): void
    {
        $customer = $this->member();
        $this->give($customer, 1000);
        $entry = StoreCreditEntry::query()->sole();

        try {
            $entry->update(['amount_minor' => 999999]);
            $this->fail('An entry was changed.');
        } catch (LogicException) {
            $this->assertSame(1000, $entry->fresh()->amount_minor);
        }
        $this->expectException(LogicException::class);
        $entry->delete();
    }

    public function test_checkout_pays_with_credit_as_far_as_it_goes_and_the_payment_is_for_the_rest(): void
    {
        $customer = $this->member();
        $this->give($customer, 150000); // Rs. 1,500

        // Rs. 2,000 order: Rs. 1,500 from credit, Rs. 500 to pay.
        $key = 'credit-checkout-1';
        $response = $this->checkout($customer, 2, true, $key)->assertCreated()
            ->assertJsonPath('data.order.grand_total_minor', 200000)
            ->assertJsonPath('data.order.store_credit_minor', 150000)
            ->assertJsonPath('data.order.payable_minor', 50000)
            ->assertJsonPath('data.payment.amount_minor', 50000);
        $this->assertSame(0, $this->balance($customer));
        $order = Order::query()->where('public_id', $response->json('data.order.id'))->sole();
        $spent = StoreCreditEntry::query()->where('type', 'spent')->sole();
        $this->assertSame([-150000, 'order', $order->id], [$spent->amount_minor, $spent->reference_type, $spent->reference_id]);

        // The same checkout sent again takes nothing more.
        $this->postJson('/api/v1/checkout', ['payment_method' => 'cod', 'use_store_credit' => true, 'idempotency_key' => $key], $this->as($customer))->assertOk();
        $this->assertSame(1, StoreCreditEntry::query()->where('type', 'spent')->count());

        // Without the wish, credit is not touched.
        $this->give($customer, 500000);
        $this->checkout($customer, 1, false)->assertCreated()->assertJsonPath('data.order.store_credit_minor', 0)->assertJsonPath('data.payment.amount_minor', 100000);
        $this->assertSame(500000, $this->balance($customer));

        // Credit that covers everything: nothing left to pay, and the order is paid at once.
        $this->checkout($customer, 1, true)->assertCreated()
            ->assertJsonPath('data.order.store_credit_minor', 100000)->assertJsonPath('data.order.payable_minor', 0)
            ->assertJsonPath('data.payment.amount_minor', 0)->assertJsonPath('data.payment.status', 'paid')->assertJsonPath('data.order.payment_status', 'paid');
        $this->assertSame(400000, $this->balance($customer));

        // The customer sees their own balance and history, without staff names.
        $own = $this->getJson('/api/v1/customer/store-credit', $this->as($customer))->assertOk()->assertJsonPath('data.balance_minor', 400000);
        $this->assertArrayNotHasKey('by', $own->json('data.entries.0'));
    }

    public function test_an_order_staff_take_can_be_paid_with_the_customers_credit_once_and_names_who_took_it(): void
    {
        $customer = $this->member();
        $this->give($customer, 150000);
        $order = fn (array $extra, string $key) => $this->actingAs($this->owner)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]], 'customer' => $customer->public_id,
            'source' => 'admin', 'idempotency_key' => $key, ...$extra,
        ]);

        // Credit needs a customer, and a way to pay the rest.
        $this->actingAs($this->owner)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'guest_name' => 'Walk In', 'guest_email' => 'walk@example.com',
            'payment_method' => 'cod', 'use_store_credit' => true, 'idempotency_key' => 'staff-guest',
        ])->assertStatus(422)->assertJsonValidationErrors('use_store_credit');
        $order(['use_store_credit' => true], 'staff-no-method')->assertStatus(422)->assertJsonValidationErrors('payment_method');
        $this->assertSame(150000, $this->balance($customer));

        // Rs. 2,000 order, Rs. 1,500 credit: the payment is for Rs. 500.
        $created = $order(['use_store_credit' => true, 'payment_method' => 'cod'], 'staff-credit')->assertCreated()
            ->assertJsonPath('data.store_credit_minor', 150000)->assertJsonPath('data.payable_minor', 50000);
        $this->assertSame(50000, Payment::query()->where('order_id', Order::query()->where('public_id', $created->json('data.id'))->value('id'))->value('amount_minor'));
        $this->assertSame(0, $this->balance($customer));
        $this->assertSame($this->owner->id, StoreCreditEntry::query()->where('type', 'spent')->value('actor_user_id'));

        // The same request again spends nothing more.
        $this->give($customer, 10000);
        $order(['use_store_credit' => true, 'payment_method' => 'cod'], 'staff-credit')->assertOk();
        $this->assertSame(10000, $this->balance($customer));

        // Credit that covers everything: nothing is charged, the order is paid.
        $this->give($customer, 500000);
        $paid = $order(['use_store_credit' => true, 'payment_method' => 'cod'], 'staff-all-credit')->assertCreated()->assertJsonPath('data.payable_minor', 0);
        $this->assertSame('paid', Payment::query()->where('order_id', Order::query()->where('public_id', $paid->json('data.id'))->value('id'))->value('status')->value);
        $this->assertSame(310000, $this->balance($customer));
    }

    public function test_credit_expires_only_when_the_store_turns_expiry_on_and_the_expiring_credit_is_spent_first(): void
    {
        $customer = $this->member();

        // Off by default: credit has no date, and the daily run does nothing.
        $this->give($customer, 5000);
        $this->travel(5)->years();
        $this->artisan('store-credit:expire')->assertSuccessful();
        $this->assertSame(5000, $this->balance($customer));
        $this->travelBack();

        // The store turns it on: credit given from now expires after 30 days.
        $this->storeSetting('store_credit.expires', true);
        $this->storeSetting('store_credit.expiry_days', 30);
        $this->give($customer, 10000);
        $this->assertSame(10000, app(StoreCreditService::class)->nextExpiry($customer, 'PKR')['amount_minor']);

        // Spending takes from the credit that expires first.
        app(StoreCreditService::class)->debit($customer, 'PKR', 3000, StoreCreditEntryType::Adjustment, 'take-3000', null, 'Test', $this->owner->id);
        $this->assertSame(7000, app(StoreCreditService::class)->nextExpiry($customer, 'PKR')['amount_minor']);
        $this->getJson('/api/v1/customer/store-credit', $this->as($customer))->assertOk()->assertJsonPath('data.next_expiry.amount_minor', 7000);

        // Not yet due.
        $this->travel(29)->days();
        $this->artisan('store-credit:expire')->assertSuccessful();
        $this->assertSame(12000, $this->balance($customer));

        // Due: what is left of it lapses, on the record; the older credit stays.
        $this->travel(2)->days();
        $this->artisan('store-credit:expire')->assertSuccessful();
        $this->artisan('store-credit:expire')->assertSuccessful();
        $this->assertSame(5000, $this->balance($customer));
        $expired = StoreCreditEntry::query()->where('type', 'expired')->get();
        $this->assertCount(1, $expired);
        $this->assertSame(-7000, $expired->first()->amount_minor);
        $this->assertNull(app(StoreCreditService::class)->nextExpiry($customer, 'PKR'));
        $this->assertSame(5000, (int) DB::table('store_credit_lots')->sum('remaining_minor'));
    }

    public function test_cancelling_an_order_gives_its_credit_back_once(): void
    {
        $customer = $this->member();
        $this->give($customer, 60000);
        $response = $this->checkout($customer, 1, true)->assertCreated()->assertJsonPath('data.order.store_credit_minor', 60000);
        $this->assertSame(0, $this->balance($customer));

        $this->asStaff();
        $this->actingAs($this->owner)->postJson('/api/v1/orders/'.$response->json('data.order.id').'/cancel', ['reason' => 'customer_request'])->assertOk();
        $this->assertSame(60000, $this->balance($customer));
        $this->assertSame(1, StoreCreditEntry::query()->where('type', 'order_cancelled')->count());
    }

    public function test_a_return_pays_back_to_the_payment_first_and_to_the_credit_it_came_from(): void
    {
        $customer = $this->member();
        $this->give($customer, 150000);
        // Rs. 2,000: Rs. 1,500 credit + Rs. 500 cash on delivery.
        $order = Order::query()->where('public_id', $this->checkout($customer, 2, true)->assertCreated()->json('data.order.id'))->sole();

        $this->asStaff();
        $shipment = $this->actingAs($this->owner)->postJson('/api/v1/shipments', [
            'order_id' => $order->public_id, 'warehouse_id' => $this->inventory->warehouse_id, 'carrier' => 'store_pickup',
            'items' => [['order_item_id' => $order->items()->value('id'), 'quantity' => 2]], 'idempotency_key' => 'ship-credit-1',
        ])->assertCreated()->json('data.id');
        foreach (['picked_up', 'delivered'] as $status) {
            $this->actingAs($this->owner)->postJson("/api/v1/shipments/{$shipment}/status", ['status' => $status])->assertSuccessful();
        }
        $payment = Payment::query()->where('order_id', $order->id)->sole();
        $this->actingAs($this->owner)->postJson("/api/v1/payments/{$payment->id}/manual-confirm", ['amount_minor' => 50000, 'reference' => 'CASH'])->assertCreated();

        // Both units come back: Rs. 2,000 is owed — Rs. 500 to the payment, Rs. 1,500 back to credit.
        $id = $this->inspected($order, 2, 2, 0, 0);
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/approve-refund")->assertOk()->assertJsonPath('data.refund_total_minor', 200000);
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/refund")->assertOk()
            ->assertJsonPath('data.refunded_minor', 50000)->assertJsonPath('data.refunded_credit_minor', 150000)->assertJsonPath('data.refund_method', 'payment');

        $this->assertSame(150000, $this->balance($customer));
        $this->assertSame(0, $payment->fresh()->refundableAmountMinor());
        $entry = StoreCreditEntry::query()->where('type', 'return_refund')->sole();
        $this->assertSame([150000, 'return'], [$entry->amount_minor, $entry->reference_type]);
    }

    public function test_a_refund_can_be_given_as_store_credit_to_a_customer_with_an_account_only(): void
    {
        $customer = $this->member();
        $order = $this->deliveredOrder(1, $customer); // paid Rs. 1,000 in cash
        $id = $this->inspected($order, 1, 1, 0, 0);

        $this->actingAs($this->owner)->getJson("/api/v1/returns/{$id}")->assertJsonPath('data.store_credit_possible', true);
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/approve-refund", ['as_store_credit' => true])->assertOk()->assertJsonPath('data.refund_method', 'store_credit');
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/refund")->assertOk()
            ->assertJsonPath('data.refunded_credit_minor', 100000)->assertJsonPath('data.status', 'completed');

        $this->assertSame(100000, $this->balance($customer));
        // The payment's part is on record, so the same money cannot also be refunded in cash.
        $payment = Payment::query()->where('order_id', $order->id)->sole();
        $this->assertSame(0, $payment->refundableAmountMinor());
        $this->actingAs($this->owner)->postJson("/api/v1/payments/{$payment->id}/refund", ['amount_minor' => 100, 'reason' => 'again', 'idempotency_key' => 'cash-again-1'])->assertStatus(422);
        // A repeat gives no second credit.
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/refund")->assertStatus(409);
        $this->assertSame(100000, $this->balance($customer));

        // A guest has no account to hold credit.
        $guestOrder = $this->deliveredOrder(1);
        $guestReturn = $this->inspected($guestOrder, 1, 1, 0, 0);
        $this->actingAs($this->owner)->getJson("/api/v1/returns/{$guestReturn}")->assertJsonPath('data.store_credit_possible', false);
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$guestReturn}/approve-refund", ['as_store_credit' => true])->assertUnprocessable()->assertJsonValidationErrors('as_store_credit');
    }

    public function test_credit_follows_a_merge_and_ends_with_an_erasure(): void
    {
        $account = $this->member(['email' => 'stay@example.com']);
        $duplicate = Customer::factory()->for($this->store)->create(['email' => 'dup@example.com', 'password' => null]);
        $this->give($account, 10000);
        $this->give($duplicate, 25000);

        $this->actingAs($this->owner)->postJson("/api/v1/customers/{$duplicate->public_id}/merge", ['into' => $account->public_id, 'confirm_email' => 'stay@example.com', 'reason' => 'Same person'])->assertOk();
        $this->assertSame(35000, $this->balance($account));
        $this->assertSame(0, $this->balance($duplicate));
        $this->assertSame(2, StoreCreditEntry::query()->where('customer_id', $account->id)->count());
        // Phase B35: what is left of each credit follows into the joined account.
        $joined = DB::table('store_credit_accounts')->where('customer_id', $account->id)->value('id');
        $this->assertSame(35000, (int) DB::table('store_credit_lots')->where('account_id', $joined)->sum('remaining_minor'));

        $this->actingAs($this->owner)->postJson("/api/v1/customers/{$account->public_id}/erase", ['reason' => 'Request', 'confirm_email' => 'stay@example.com'])->assertOk();
        $this->assertSame(0, $this->balance($account));
        $this->assertSame(-35000, StoreCreditEntry::query()->where('type', 'erased')->sole()->amount_minor);
        $this->assertSame(0, (int) DB::table('store_credit_accounts')->sum('balance_minor'));
        $this->assertSame(0, (int) DB::table('store_credit_lots')->sum('remaining_minor'));
    }
}
