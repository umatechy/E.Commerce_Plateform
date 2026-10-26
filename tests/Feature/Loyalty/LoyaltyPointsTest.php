<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Compliance\Services\CustomerDataService;
use App\Domain\Identity\Models\User;
use App\Domain\Loyalty\Models\LoyaltyEntry;
use App\Domain\Loyalty\Models\LoyaltyOrderAward;
use App\Domain\Loyalty\Services\LoyaltyEngine;
use App\Domain\Loyalty\Services\LoyaltyLedger;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Support\FeatureAvailability;
use App\Domain\StoreCredit\Models\StoreCreditEntry;
use App\Domain\StoreCredit\Services\StoreCreditService;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use LogicException;
use Tests\Feature\Returns\BuildsReturns;
use Tests\TestCase;

/**
 * Phase P2 — loyalty points (Module 10 §45, Module 15 §45, Module 14 §76;
 * Premium): the store's own rules, points that follow real orders (pending,
 * earned, taken back on refunds), tiers, repeat-purchase and birthday
 * rewards, expiry, turning points into store credit, staff corrections,
 * privacy and store isolation.
 */
final class LoyaltyPointsTest extends TestCase
{
    use BuildsReturns, RefreshDatabase;

    /** 10 points for every Rs. 100; 100 points = Rs. 10 — 1% back. */
    private function rules(array $more = []): array
    {
        return [
            'earn_points' => 10, 'earn_spend_minor' => 10000, 'redeem_points' => 100, 'redeem_value_minor' => 1000, 'min_redeem_points' => 100,
            'hold_days' => 0, 'expiry_days' => null, 'birthday_points' => 0, 'repeat_every' => null, 'repeat_points' => 0, 'tiers' => [], ...$more,
        ];
    }

    private function includeLoyalty(): void
    {
        Subscription::query()->withoutGlobalScopes()->where('store_id', $this->store->id)->sole()->package
            ->entitlements()->create(['key' => 'loyalty.points', 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        \Illuminate\Support\Facades\Cache::flush();
    }

    private function running(array $more = []): void
    {
        $this->includeLoyalty();
        $this->actingAs($this->owner)->putJson('/api/v1/loyalty/program', $this->rules($more))->assertOk();
        $this->actingAs($this->owner)->postJson('/api/v1/loyalty/program/enable')->assertOk();
    }

    private function member(array $attributes = []): Customer
    {
        return Customer::factory()->for($this->store)->create(['password' => Hash::make('correct-horse-99'), ...$attributes]);
    }

    private function follow(): void
    {
        $this->asStaff();
        Artisan::call('loyalty:follow-up');
        app(\App\Domain\Tenancy\Support\TenantContext::class)->resolveToStore($this->store->id);
    }

    private function points(Customer $customer): int
    {
        return app(LoyaltyLedger::class)->balance($customer);
    }

    private function award(Order $order): LoyaltyOrderAward
    {
        return LoyaltyOrderAward::query()->where('order_id', $order->id)->sole();
    }

    private function refund(Order $order, int $quantity): void
    {
        $id = $this->inspected($order, $quantity, $quantity, 0, 0);
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/approve-refund")->assertOk();
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/refund")->assertOk();
    }

    public function test_the_rates_are_the_stores_own_impossible_ones_are_refused_and_the_package_decides(): void
    {
        $this->assertFalse(FeatureAvailability::isComingSoon('loyalty.points'), 'P2 built it: it is no longer "coming soon"');
        $this->actingAs($this->owner)->getJson('/api/v1/loyalty/overview')->assertOk()
            ->assertJsonPath('data.program', null)->assertJsonPath('data.included', false)->assertJsonPath('data.running', false);

        // Nothing is proposed: every rate is required.
        $this->actingAs($this->owner)->putJson('/api/v1/loyalty/program', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['earn_points', 'earn_spend_minor', 'redeem_points', 'redeem_value_minor', 'min_redeem_points']);
        // Giving back more than the customer spends cannot be meant (here 10 points per Rs. 1, each worth Rs. 1).
        $this->actingAs($this->owner)->putJson('/api/v1/loyalty/program', $this->rules(['earn_spend_minor' => 100, 'redeem_points' => 10, 'redeem_value_minor' => 1000]))
            ->assertUnprocessable()->assertJsonValidationErrors('redeem_value_minor');
        // ...also through the top tier's bonus.
        $this->actingAs($this->owner)->putJson('/api/v1/loyalty/program', $this->rules(['redeem_value_minor' => 50000, 'tiers' => [['name' => 'Gold', 'min_points' => 500, 'bonus_percent' => 200]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('redeem_value_minor');
        $this->actingAs($this->owner)->putJson('/api/v1/loyalty/program', $this->rules(['tiers' => [['name' => 'Gold', 'min_points' => 500, 'bonus_percent' => 10], ['name' => 'gold', 'min_points' => 900, 'bonus_percent' => 20]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('tiers.1.name');
        $this->actingAs($this->owner)->putJson('/api/v1/loyalty/program', $this->rules(['min_redeem_points' => 50]))->assertUnprocessable()->assertJsonValidationErrors('min_redeem_points');
        $this->actingAs($this->owner)->putJson('/api/v1/loyalty/program', $this->rules(['repeat_points' => 50]))->assertUnprocessable()->assertJsonValidationErrors('repeat_every');

        $this->actingAs($this->owner)->putJson('/api/v1/loyalty/program', $this->rules(['tiers' => [['name' => 'Gold', 'min_points' => 2000, 'bonus_percent' => 50], ['name' => 'Silver', 'min_points' => 500, 'bonus_percent' => 20]]]))
            ->assertOk()->assertJsonPath('data.is_enabled', false)->assertJsonPath('data.currency', 'PKR')
            ->assertJsonPath('data.tiers.0.name', 'Silver')->assertJsonPath('data.return_rate', 0.01);
        $this->assertSame(1, AuditLog::query()->where('action', 'loyalty.program_saved')->count());

        // Saved, but not in the package: it cannot be turned on.
        $this->actingAs($this->owner)->postJson('/api/v1/loyalty/program/enable')->assertStatus(409)->assertJsonPath('code', 'feature_not_entitled');
        $this->includeLoyalty();
        $this->actingAs($this->owner)->postJson('/api/v1/loyalty/program/enable')->assertOk()->assertJsonPath('data.is_enabled', true);
        $this->actingAs($this->owner)->getJson('/api/v1/loyalty/overview')->assertJsonPath('data.running', true);

        // Looking is one permission, running it another.
        $viewer = $this->staffWith(['loyalty.view']);
        $this->asStaff();
        $this->actingAs($viewer)->getJson('/api/v1/loyalty/overview')->assertOk()->assertJsonPath('data.can_manage', false)->assertJsonPath('data.can_adjust', false);
        $this->actingAs($viewer)->putJson('/api/v1/loyalty/program', $this->rules())->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/v1/loyalty/program/disable')->assertForbidden();
        $this->actingAs($this->staffWith(['customers.view']))->getJson('/api/v1/loyalty/overview')->assertForbidden();
    }

    public function test_an_order_earns_pending_points_that_are_earned_once_it_is_paid_delivered_and_the_hold_is_over(): void
    {
        $customer = $this->member();
        $before = $this->deliveredOrder(1, $customer); // placed before the program started
        $this->travel(1)->minutes();
        $this->running(['hold_days' => 7]);
        $this->travel(1)->minutes();

        $order = $this->deliveredOrder(3, $customer, paid: false, deliver: false); // Rs. 3,000
        $guest = $this->deliveredOrder(3);
        $this->follow();
        $this->assertSame(['pending', 300], [$this->award($order)->status, $this->award($order)->points]);
        $this->assertSame(0, LoyaltyOrderAward::query()->whereIn('order_id', [$before->id, $guest->id])->count(), 'earlier orders and guests earn nothing');

        $own = $this->getJson('/api/v1/customer/loyalty', $this->as($customer))->assertOk()
            ->assertJsonPath('data.balance', 0)->assertJsonPath('data.pending_points', 300)->assertJsonPath('data.program.earning', true)->json('data');
        $this->assertArrayNotHasKey('by', $own['entries'][0] ?? []);

        // Delivered and paid: the hold starts.
        $this->asStaff();
        $payment = \App\Domain\Payments\Models\Payment::query()->where('order_id', $order->id)->sole();
        DB::table('orders')->where('id', $order->id)->update(['status' => 'delivered']);
        DB::table('order_timeline_events')->insert(['store_id' => $this->store->id, 'order_id' => $order->id, 'event_type' => 'status_changed', 'from_status' => 'shipped', 'to_status' => 'delivered', 'created_at' => now()]);
        $this->actingAs($this->owner)->postJson("/api/v1/payments/{$payment->id}/manual-confirm", ['amount_minor' => $order->grand_total_minor, 'reference' => 'CASH'])->assertCreated();
        $this->follow();
        $this->assertSame('pending', $this->award($order)->status);
        $this->assertNotNull($this->award($order)->hold_until);

        $this->travel(8)->days();
        $this->follow();
        $this->follow(); // again: nothing changes
        $this->assertSame('earned', $this->award($order)->status);
        $this->assertSame(300, $this->points($customer));
        $this->assertSame(1, LoyaltyEntry::query()->where('type', 'earned')->count());
        $this->getJson('/api/v1/customer/loyalty', $this->as($customer))->assertJsonPath('data.balance', 300)->assertJsonPath('data.value_minor', 3000)
            ->assertJsonPath('data.pending_points', 0)->assertJsonPath('data.entries.0.type', 'earned');

        // A pending order that is cancelled earns nothing.
        $this->asStaff();
        $cancelled = $this->deliveredOrder(1, $customer, paid: false, deliver: false);
        $this->follow();
        DB::table('orders')->where('id', $cancelled->id)->update(['status' => 'cancelled']);
        $this->follow();
        $this->assertSame('cancelled', $this->award($cancelled)->status);
        $this->assertSame(300, $this->points($customer));

        // Turned off: new orders earn nothing, the points stay.
        $this->actingAs($this->owner)->postJson('/api/v1/loyalty/program/disable')->assertOk();
        $this->deliveredOrder(2, $customer);
        $this->follow();
        $this->assertSame(2, LoyaltyOrderAward::query()->count());
        $this->assertSame(300, $this->points($customer));
    }

    public function test_points_turn_into_store_credit_in_whole_steps_and_a_refund_takes_back_its_share_without_going_negative(): void
    {
        $this->running();
        $customer = $this->member();
        $order = $this->deliveredOrder(3, $customer);
        $this->follow();
        $this->assertSame(300, $this->points($customer));

        $headers = $this->as($customer);
        $this->postJson('/api/v1/customer/loyalty/redeem', ['points' => 50, 'idempotency_key' => 'redeem-0001'], $headers)->assertUnprocessable()->assertJsonPath('code', 'below_minimum');
        $this->postJson('/api/v1/customer/loyalty/redeem', ['points' => 150, 'idempotency_key' => 'redeem-0002'], $headers)->assertUnprocessable()->assertJsonPath('code', 'not_a_block');
        $this->postJson('/api/v1/customer/loyalty/redeem', ['points' => 400, 'idempotency_key' => 'redeem-0003'], $headers)->assertUnprocessable()->assertJsonPath('code', 'insufficient_points');
        $body = ['points' => 200, 'idempotency_key' => 'redeem-0004'];
        $this->postJson('/api/v1/customer/loyalty/redeem', $body, $headers)->assertCreated()->assertJsonPath('data.credited_minor', 2000)->assertJsonPath('data.balance', 100);
        $this->postJson('/api/v1/customer/loyalty/redeem', $body, $this->as($customer))->assertCreated()->assertJsonPath('data.balance', 100); // a repeat moves nothing
        $this->assertSame(2000, app(StoreCreditService::class)->balance($customer, 'PKR'));
        $credit = StoreCreditEntry::query()->where('type', 'loyalty_points')->sole();
        $this->assertSame(['loyalty_entry', LoyaltyEntry::query()->where('type', 'redeemed')->sole()->id], [$credit->reference_type, $credit->reference_id]);

        // Two of the three units are refunded: 200 points are due back; 100 are left, so 100 are taken and none more.
        $this->asStaff();
        $this->refund($order, 2);
        $this->follow();
        $this->follow();
        $this->assertSame(0, $this->points($customer));
        $this->assertSame(200, $this->award($order)->reversed_points);
        $this->assertSame(-100, LoyaltyEntry::query()->where('type', 'reversed')->sole()->points);
        $this->assertSame(100, (int) DB::table('loyalty_accounts')->value('lifetime_points'), 'the tier counts what was kept');

        // The ledger is never edited or deleted.
        $entry = LoyaltyEntry::query()->firstOrFail();
        $this->expectException(LogicException::class);
        $entry->update(['points' => 1]);
    }

    public function test_tiers_add_their_bonus_every_nth_order_brings_a_bonus_and_birthdays_come_once_a_year(): void
    {
        $this->running(['tiers' => [['name' => 'Silver', 'min_points' => 300, 'bonus_percent' => 50]], 'repeat_every' => 2, 'repeat_points' => 40, 'birthday_points' => 25]);
        $customer = $this->member(['email' => 'a@example.com']);
        $customer->forceFill(['birth_month' => (int) now()->month, 'birth_day' => (int) now()->day])->save();
        $newcomer = $this->member(['email' => 'b@example.com']);
        $newcomer->forceFill(['birth_month' => (int) now()->month, 'birth_day' => (int) now()->day])->save();

        $this->deliveredOrder(3, $customer);
        $this->follow();
        $this->assertSame(300 + 25, $this->points($customer), 'first order, then the birthday of a customer who has shopped');
        $this->assertSame(0, $this->points($newcomer), 'no birthday points before a first earning order');

        $second = $this->deliveredOrder(2, $customer); // Silver now: 200 + 50%
        $this->follow();
        $this->assertSame(300, $this->award($second)->points);
        $this->assertSame(40, $this->award($second)->bonus_points);
        $this->assertSame(325 + 300 + 40, $this->points($customer));
        $this->assertSame(1, LoyaltyEntry::query()->where('type', 'birthday')->count(), 'once a year');
        $this->getJson('/api/v1/customer/loyalty', $this->as($customer))->assertJsonPath('data.tier.name', 'Silver')->assertJsonPath('data.next_tier', null);
    }

    public function test_an_inactive_balance_expires_where_the_store_set_it(): void
    {
        $this->running(['expiry_days' => 30]);
        $customer = $this->member();
        $this->deliveredOrder(1, $customer);
        $this->follow();
        $this->assertSame(100, $this->points($customer));
        $this->getJson('/api/v1/customer/loyalty', $this->as($customer))->assertJsonPath('data.expires_at', fn ($v) => is_string($v));

        $this->travel(29)->days();
        $this->follow();
        $this->assertSame(100, $this->points($customer));
        $this->travel(2)->days();
        $this->follow();
        $this->follow();
        $this->assertSame(0, $this->points($customer));
        $this->assertSame(1, LoyaltyEntry::query()->where('type', 'expired')->count());
    }

    public function test_staff_correct_points_with_their_own_permission_a_reason_and_only_in_their_store(): void
    {
        $this->running();
        $customer = $this->member();
        $url = "/api/v1/customers/{$customer->public_id}/loyalty";

        $this->actingAs($this->owner)->postJson("{$url}/adjust", ['points' => 50, 'idempotency_key' => 'adj-00001'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $body = ['points' => 500, 'note' => 'Welcome gift for a wholesale buyer', 'idempotency_key' => 'adj-00002'];
        $this->actingAs($this->owner)->postJson("{$url}/adjust", $body)->assertCreated()->assertJsonPath('data.balance', 500)->assertJsonPath('data.entries.0.by', $this->owner->name);
        $this->actingAs($this->owner)->postJson("{$url}/adjust", $body)->assertCreated()->assertJsonPath('data.balance', 500);
        $this->assertSame(1, AuditLog::query()->where('action', 'loyalty.adjusted')->count());
        $this->actingAs($this->owner)->postJson("{$url}/adjust", ['points' => -501, 'note' => 'Mistake', 'idempotency_key' => 'adj-00003'])->assertUnprocessable()->assertJsonPath('code', 'insufficient_points');
        $this->assertSame(0, (int) DB::table('loyalty_accounts')->value('lifetime_points'), 'gifts do not raise the tier');

        $viewer = $this->staffWith(['customers.view', 'loyalty.view', 'loyalty.manage']);
        $this->asStaff();
        $this->actingAs($viewer)->getJson($url)->assertOk()->assertJsonPath('data.balance', 500)->assertJsonPath('data.can_adjust', false);
        $this->actingAs($viewer)->postJson("{$url}/adjust", ['points' => 1, 'note' => 'x', 'idempotency_key' => 'adj-00004'])->assertForbidden();
        $this->actingAs($viewer)->getJson('/api/v1/loyalty/members')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.balance', 500);

        // Another store sees none of it.
        $outsider = User::factory()->create();
        $elsewhere = Store::factory()->create();
        $elsewhere->users()->attach($outsider, ['role_id' => $this->systemRole($elsewhere, 'owner')->id, 'status' => 'active']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($outsider)->getJson($url)->assertNotFound();
        $this->actingAs($outsider)->postJson("{$url}/adjust", ['points' => 1, 'note' => 'x', 'idempotency_key' => 'adj-00005'])->assertNotFound();
        $this->actingAs($outsider)->getJson('/api/v1/loyalty/members')->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAs($outsider)->getJson('/api/v1/loyalty/overview')->assertOk()->assertJsonPath('data.program', null);
    }

    public function test_the_birthday_is_the_customers_own_choice_and_erasure_ends_the_points(): void
    {
        $this->running();
        $customer = $this->member();
        $headers = $this->as($customer);
        $this->patchJson('/api/v1/customer/profile', ['birthday' => ['month' => 2, 'day' => 30]], $headers)->assertUnprocessable()->assertJsonValidationErrors('birthday.day');
        $this->patchJson('/api/v1/customer/profile', ['birthday' => ['month' => 2, 'day' => 29]], $headers)->assertOk()->assertJsonPath('data.birthday', ['month' => 2, 'day' => 29]);
        $this->patchJson('/api/v1/customer/profile', ['name' => 'Same Person'], $headers)->assertOk()->assertJsonPath('data.birthday.day', 29);
        $this->patchJson('/api/v1/customer/profile', ['birthday' => null], $headers)->assertOk()->assertJsonPath('data.birthday', null);

        $this->asStaff();
        $customer->forceFill(['birth_month' => 5, 'birth_day' => 4])->save();
        app(LoyaltyLedger::class)->move($customer, 700, \App\Domain\Loyalty\Models\LoyaltyEntryType::Adjustment, 'seed-1', null, 'Test');
        $this->assertSame('05-04', app(CustomerDataService::class)->export($customer)['customer']['birthday']);
        $this->assertCount(1, app(CustomerDataService::class)->export($customer)['loyalty_points']);

        app(CustomerDataService::class)->erase($customer->fresh());
        $this->assertSame(0, $this->points($customer));
        $this->assertSame([null, null], [$customer->fresh()->birth_month, $customer->fresh()->birth_day]);
        $this->assertSame(1, LoyaltyEntry::query()->where('type', 'erased')->count());
    }

    public function test_the_storefront_shows_the_program_only_while_it_earns_points(): void
    {
        $shell = function (): mixed {
            $this->app['auth']->forgetGuards();
            $loyalty = 'unset';
            $this->withoutVite()->get("/shop/{$this->store->slug}/")->assertOk()
                ->assertInertia(function (AssertableInertia $page) use (&$loyalty) {
                    $loyalty = $page->toArray()['props']['storefront']['loyalty'] ?? null;
                });

            return $loyalty;
        };
        $this->assertNull($shell());
        $this->running(['birthday_points' => 20]);
        $this->assertSame(['earning' => true, 'earn_points' => 10, 'earn_spend_minor' => 10000, 'birthday_points' => 20], array_intersect_key($shell(), array_flip(['earning', 'earn_points', 'earn_spend_minor', 'birthday_points'])));
        $this->asStaff();
        $this->actingAs($this->owner)->postJson('/api/v1/loyalty/program/disable')->assertOk();
        $this->assertNull($shell());
    }

    public function test_merging_customers_joins_their_points(): void
    {
        $this->running();
        $this->entitle($this->store, ['customers.advanced']);
        $keep = $this->member(['email' => 'keep@example.com']);
        // A guest record (no account): its orders earn nothing, but staff may have given it points.
        $from = Customer::factory()->for($this->store)->create(['email' => 'from@example.com', 'password' => null]);
        $this->deliveredOrder(1, $keep);
        $guestOrder = $this->deliveredOrder(2, $from);
        $this->follow();
        $this->assertSame(0, LoyaltyOrderAward::query()->where('order_id', $guestOrder->id)->count());
        app(LoyaltyLedger::class)->move($from, 200, \App\Domain\Loyalty\Models\LoyaltyEntryType::Adjustment, 'seed-guest', null, 'Phone order promise');
        $this->assertSame([100, 200], [$this->points($keep), $this->points($from)]);

        app(\App\Domain\Customers\Services\CustomerMerger::class)->merge($from, $keep, "Same person", $this->owner);
        $this->assertSame(300, $this->points($keep->fresh()));
        $this->assertSame(0, $this->points($from->fresh()));
        $this->assertSame(2, LoyaltyEntry::query()->where('customer_id', $keep->id)->count());
    }
}
