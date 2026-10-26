<?php

declare(strict_types=1);

namespace Tests\Feature\Affiliates;

use App\Domain\Affiliates\Models\Affiliate;
use App\Domain\Affiliates\Models\AffiliateCommission;
use App\Domain\Affiliates\Services\AffiliateAttribution;
use App\Domain\Affiliates\Services\AffiliateLedger;
use App\Domain\Affiliates\Services\AffiliateProgramService;
use App\Domain\Affiliates\Support\AffiliateRules;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Orders\Models\Order;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Promotions\Models\Coupon;
use App\Domain\Promotions\Models\Promotion;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase B57 (gap G20 part 1; Module 28): the store affiliate program —
 * signed referral attribution, commission snapshots, self-referral,
 * qualification and reversals, payouts and adjustments, applications and
 * the affiliate's portal, permissions and store isolation.
 */
final class AffiliateProgramTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    /** @return array{0: Store, 1: User, 2: Product, 3: int} */
    private function store(bool $affiliates = true): array
    {
        $store = Store::factory()->create(['status' => 'active']);
        $package = Package::factory()->create();
        foreach (['orders.basic', 'payment.cod', 'shipping.basic', ...($affiliates ? ['affiliate.programs'] : [])] as $key) {
            $package->entitlements()->create(['key' => $key, 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        }
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);
        $product = Product::factory()->for($store)->create(['status' => 'active', 'visibility' => 'public', 'price_minor' => 10000, 'currency' => 'USD']);
        $warehouse = Warehouse::query()->withoutTenantScope()->where('store_id', $store->id)->where('is_default', true)->firstOrFail();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['product_id' => $product->id]);
        app(TenantContext::class)->resolveToStore($store->id);
        app(InventoryService::class)->setOpeningStock($inventory, 100, 'Init', actorId: $this->actorId(), idempotencyKey: 'open-'.$store->id);
        $pickup = (int) \App\Domain\Shipping\Models\ShippingMethod::query()->where('store_id', $store->id)->where('type', 'store_pickup')->value('id');

        return [$store, $owner, $product, $pickup];
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $this->actingAs($user);
    }

    private function rules(array $more = []): array
    {
        return ['name' => 'Partners', 'accepts_applications' => true, 'terms' => 'Be honest.', 'commission_type' => 'percentage', 'rate_percent' => 10,
            'window_days' => 30, 'hold_days' => 14, 'require_delivery' => true, 'require_payment' => true, 'new_customers_only' => false,
            'min_order_minor' => 0, 'cap_per_order_minor' => null, 'payout_threshold_minor' => 0, 'self_referral' => 'review', ...$more];
    }

    /** @return string the affiliate's code */
    private function runningProgramWithAffiliate(User $owner, array $rules = [], array $affiliate = []): string
    {
        $this->as($owner)->putJson('/api/v1/affiliates/program', $this->rules($rules))->assertOk();
        $this->as($owner)->postJson('/api/v1/affiliates/program/activate')->assertOk();

        return (string) $this->as($owner)->postJson('/api/v1/affiliates', ['name' => 'Sana Ali', 'email' => 'sana@example.com', ...$affiliate])->assertCreated()->json('data.code');
    }

    private function token(Store $store, string $code): ?string
    {
        $this->app['auth']->forgetGuards();
        $response = $this->withoutVite()->get("/shop/{$store->slug}/?ref=".strtolower($code))->assertOk();
        $token = null;
        $response->assertInertia(function (AssertableInertia $page) use (&$token) {
            $token = $page->toArray()['props']['storefront']['referral']['token'] ?? null;
        });

        return $token;
    }

    private function checkout(Store $store, Product $product, int $pickup, ?string $referral, string $email = 'shopper@example.com', string $key = 'k1'): Order
    {
        $this->app['auth']->forgetGuards();
        $cart = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 2], ['X-Store-Slug' => $store->slug])->headers->get('X-Guest-Cart-Token');
        $id = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod', 'shipping_method_id' => $pickup, 'shipping_address' => ['country' => 'PK'],
            'guest_name' => 'Shopper', 'guest_email' => $email, 'idempotency_key' => $key, 'referral_token' => $referral,
        ], ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $cart])->assertCreated()->json('data.order.id');

        return Order::query()->withoutGlobalScopes()->where('public_id', $id)->firstOrFail();
    }

    private function deliverAndPay(Order $order, string $deliveredAt): void
    {
        DB::table('orders')->where('id', $order->id)->update(['status' => 'delivered', 'payment_status' => 'paid']);
        DB::table('order_timeline_events')->insert(['store_id' => $order->store_id, 'order_id' => $order->id, 'event_type' => 'status_changed', 'from_status' => 'shipped', 'to_status' => 'delivered', 'created_at' => $deliveredAt]);
    }

    // --- Attribution (§8–16) ---

    public function test_a_referral_link_brings_a_signed_token_and_the_order_earns_its_commission_once(): void
    {
        [$store, $owner, $product, $pickup] = $this->store();
        $code = $this->runningProgramWithAffiliate($owner);

        $token = $this->token($store, $code);
        $this->assertNotNull($token);
        $this->token($store, $code); // the same visitor again: one click a day
        $this->assertSame(1, (int) DB::table('affiliate_clicks')->value('clicks'));
        $this->assertNull($this->token($store, 'NOSUCHCODE'));

        $order = $this->checkout($store, $product, $pickup, $token);
        $commission = AffiliateCommission::query()->withoutTenantScope()->where('order_id', $order->id)->sole();
        $this->assertSame(['pending', 'link', 20000, 2000], [$commission->status, $commission->attribution_source, $commission->base_minor, $commission->amount_minor]);
        $this->assertSame(1, $commission->snapshot['program_version'], 'the first saved rules are version 1');
        $this->assertSame(1000, $commission->snapshot['rate_bp']);

        $tampered = substr($token, 0, -2).'00';
        $plain = $this->checkout($store, $product, $pickup, $tampered, 'other@example.com', 'k2');
        $this->assertFalse(AffiliateCommission::query()->withoutTenantScope()->where('order_id', $plain->id)->exists(), 'a forged token earns nothing');

        [$otherStore, , $otherProduct, $otherPickup] = $this->store();
        $foreign = $this->checkout($otherStore, $otherProduct, $otherPickup, $token, 'third@example.com', 'k3');
        $this->assertFalse(AffiliateCommission::query()->withoutTenantScope()->where('order_id', $foreign->id)->exists(), 'a token of another store earns nothing');

        $this->travel(31)->days();
        $late = $this->checkout($store, $product, $pickup, $token, 'late@example.com', 'k4');
        $this->assertFalse(AffiliateCommission::query()->withoutTenantScope()->where('order_id', $late->id)->exists(), 'outside the attribution window');
    }

    public function test_a_coupon_linked_to_an_affiliate_wins_and_a_paused_affiliate_earns_nothing(): void
    {
        [$store, $owner] = $this->store();
        app(TenantContext::class)->resolveToStore($store->id);
        $promotion = Promotion::factory()->for($store)->create();
        $coupon = Coupon::factory()->for($store)->create(['promotion_id' => $promotion->id, 'code' => 'SANA10', 'code_normalized' => 'SANA10']);
        $this->runningProgramWithAffiliate($owner, [], ['coupon_id' => $coupon->id]);
        $other = $this->as($owner)->postJson('/api/v1/affiliates', ['name' => 'Bilal', 'email' => 'bilal@example.com'])->assertCreated()->json('data.code');

        app(TenantContext::class)->resolveToStore($store->id);
        $order = Order::factory()->for($store)->create(['subtotal_minor' => 50000, 'discount_total_minor' => 5000, 'grand_total_minor' => 45000]);
        DB::table('order_promotions')->insert(['store_id' => $store->id, 'order_id' => $order->id, 'promotion_id' => $promotion->id, 'promotion_name_snapshot' => 'Sale', 'promotion_type_snapshot' => 'percentage', 'coupon_code_snapshot' => 'sana10', 'discount_amount_minor' => 5000, 'currency' => 'USD']);
        $linkOfBilal = $this->token($store, $other);
        app(TenantContext::class)->resolveToStore($store->id);
        $commission = app(AffiliateAttribution::class)->attribute($order, $linkOfBilal);
        $this->assertSame(['coupon', 'sana@example.com', 45000, 4500], [$commission?->attribution_source, $commission?->affiliate->email, $commission?->base_minor, $commission?->amount_minor]);

        $bilal = Affiliate::query()->where('code', $other)->firstOrFail();
        $this->as($owner)->postJson("/api/v1/affiliates/{$bilal->public_id}/pause")->assertOk();
        $this->assertNull($this->token($store, $other), 'a paused affiliate’s link does not count');
    }

    public function test_an_order_by_the_affiliate_themself_is_held_for_review_or_rejected(): void
    {
        [$store, $owner, $product, $pickup] = $this->store();
        $code = $this->runningProgramWithAffiliate($owner);
        $own = $this->checkout($store, $product, $pickup, $this->token($store, $code), 'sana@example.com');
        $commission = AffiliateCommission::query()->withoutTenantScope()->where('order_id', $own->id)->sole();
        $this->assertSame(['review', ['self_referral' => ['same_email']]], [$commission->status, $commission->flags]);

        $this->as($owner)->postJson("/api/v1/affiliates/commissions/{$commission->public_id}/decide", ['decision' => 'reject', 'note' => 'Own order'])->assertOk();
        $this->assertSame('rejected', $commission->refresh()->status);

        $this->as($owner)->putJson('/api/v1/affiliates/program', $this->rules(['self_referral' => 'reject']))->assertOk();
        $again = $this->checkout($store, $product, $pickup, $this->token($store, $code), 'sana@example.com', 'k9');
        $this->assertSame('rejected', AffiliateCommission::query()->withoutTenantScope()->where('order_id', $again->id)->value('status'));
    }

    // --- Lifecycle (§21–24) ---

    /** An order whose shipments all arrived stays "confirmed": its delivery still counts (before, only staff moving the order did). */
    public function test_an_order_delivered_by_its_shipments_qualifies_without_staff_moving_the_order(): void
    {
        [$store, $owner, $product, $pickup] = $this->store();
        $code = $this->runningProgramWithAffiliate($owner);
        $order = $this->checkout($store, $product, $pickup, $this->token($store, $code));
        $commission = fn () => AffiliateCommission::query()->withoutTenantScope()->where('order_id', $order->id)->sole();
        $warehouse = \App\Domain\Inventory\Models\Warehouse::query()->withoutTenantScope()->where('store_id', $store->id)->where('is_default', true)->value('id');

        DB::table('orders')->where('id', $order->id)->update(['fulfillment_status' => 'fulfilled', 'payment_status' => 'paid']);
        DB::table('shipments')->insert(['public_id' => (string) \Illuminate\Support\Str::ulid(), 'store_id' => $store->id, 'order_id' => $order->id, 'warehouse_id' => $warehouse,
            'carrier' => 'store_pickup', 'status' => 'delivered', 'currency' => 'USD', 'delivered_at' => now()->subDays(15), 'idempotency_key' => 'ship-1', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('confirmed', $order->fresh()->status->value);

        app(AffiliateLedger::class)->followUp();
        $this->assertSame('approved', $commission()->status, 'delivered 15 days ago, paid, the 14-day hold is over');
    }

    public function test_commissions_follow_their_orders_through_delivery_hold_refund_and_cancellation(): void
    {
        [$store, $owner, $product, $pickup] = $this->store();
        $code = $this->runningProgramWithAffiliate($owner);
        $order = $this->checkout($store, $product, $pickup, $this->token($store, $code));
        $cancelled = $this->checkout($store, $product, $pickup, $this->token($store, $code), 'b@example.com', 'k2');
        $ledger = app(AffiliateLedger::class);
        $commission = fn () => AffiliateCommission::query()->withoutTenantScope()->where('order_id', $order->id)->sole();

        $ledger->followUp();
        $this->assertSame('pending', $commission()->status, 'not delivered yet: COD money not collected');

        $this->deliverAndPay($order, now()->subDays(3)->toDateTimeString());
        $ledger->followUp();
        $this->assertSame('pending', $commission()->status, 'still in the 14-day hold');
        $this->assertSame(now()->subDays(3)->addDays(14)->toDateString(), $commission()->hold_until->toDateString());

        $paymentId = DB::table('payments')->where('order_id', $order->id)->value('id');
        DB::table('payment_transactions')->insert(['store_id' => $store->id, 'payment_id' => $paymentId, 'type' => 'partial_refund', 'status' => 'succeeded', 'amount_minor' => (int) round($order->grand_total_minor / 2), 'currency' => 'USD', 'idempotency_key' => 'r1']);
        $this->travel(12)->days();
        $ledger->followUp();
        $this->assertSame(['approved', 1000], [$commission()->status, $commission()->reversed_minor], 'half refunded: half taken back; hold over: approved');

        DB::table('orders')->where('id', $cancelled->id)->update(['status' => 'cancelled']);
        $ledger->followUp();
        $this->assertSame('rejected', AffiliateCommission::query()->withoutTenantScope()->where('order_id', $cancelled->id)->value('status'));
        $this->assertSame(['approved' => 0, 'reversed' => 0, 'rejected' => 0], $ledger->followUp(), 'running again changes nothing');
    }

    // --- Payouts (§33–39) ---

    public function test_payouts_gather_what_is_payable_once_and_refunds_after_payment_become_adjustments(): void
    {
        [$store, $owner, $product, $pickup] = $this->store();
        $code = $this->runningProgramWithAffiliate($owner, ['payout_threshold_minor' => 3000, 'hold_days' => 0]);
        $affiliate = Affiliate::query()->withoutTenantScope()->where('code', $code)->firstOrFail();
        $first = $this->checkout($store, $product, $pickup, $this->token($store, $code));
        $this->deliverAndPay($first, now()->toDateTimeString());
        app(AffiliateLedger::class)->followUp();

        $this->as($owner)->postJson("/api/v1/affiliates/{$affiliate->public_id}/payouts")->assertUnprocessable()->assertJsonPath('code', 'no_payout_details');
        $this->as($owner)->putJson("/api/v1/affiliates/{$affiliate->public_id}/payout-details", ['method' => 'jazzcash', 'account_title' => 'Sana Ali', 'account_number' => '0300 0000000'])->assertOk()
            ->assertJsonPath('data.account', '•••••••0000');
        $this->as($owner)->postJson("/api/v1/affiliates/{$affiliate->public_id}/payouts")->assertUnprocessable()->assertJsonPath('code', 'below_threshold');

        $second = $this->checkout($store, $product, $pickup, $this->token($store, $code), 'c@example.com', 'k2');
        $this->deliverAndPay($second, now()->toDateTimeString());
        app(AffiliateLedger::class)->followUp();
        $payout = $this->as($owner)->postJson("/api/v1/affiliates/{$affiliate->public_id}/payouts")->assertCreated()->assertJsonPath('data.amount_minor', 4000)
            ->assertJsonPath('data.pay_to.account_number', '03000000000')->json('data.id');
        $this->as($owner)->postJson("/api/v1/affiliates/payouts/{$payout}/pay-to")->assertOk()->assertJsonPath('data.account_number', '03000000000');
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'affiliates.payout_details.revealed')->count(), 'every look at the full account is audited');
        $this->as($owner)->postJson("/api/v1/affiliates/{$affiliate->public_id}/payouts")->assertUnprocessable()->assertJsonPath('code', 'payout_open');
        $this->as($owner)->postJson("/api/v1/affiliates/payouts/{$payout}/paid", ['reference' => 'JC-778899'])->assertOk();
        $this->as($owner)->postJson("/api/v1/affiliates/payouts/{$payout}/paid", ['reference' => 'JC-778899'])->assertUnprocessable()->assertJsonPath('code', 'payout_not_pending');
        $this->as($owner)->postJson("/api/v1/affiliates/payouts/{$payout}/pay-to")->assertNotFound();
        $this->assertSame(2, AffiliateCommission::query()->withoutTenantScope()->where('status', 'paid')->count());

        // A full refund after the payout: taken back from the next one.
        $paymentId = DB::table('payments')->where('order_id', $first->id)->value('id');
        DB::table('payment_transactions')->insert(['store_id' => $store->id, 'payment_id' => $paymentId, 'type' => 'refund', 'status' => 'succeeded', 'amount_minor' => $first->grand_total_minor, 'currency' => 'USD', 'idempotency_key' => 'r1']);
        app(AffiliateLedger::class)->followUp();
        app(AffiliateLedger::class)->followUp();
        app(TenantContext::class)->resolveToStore($store->id);
        $this->assertSame(-2000, (int) DB::table('affiliate_adjustments')->sum('amount_minor'));
        $this->assertSame(-2000, app(AffiliateLedger::class)->balances($affiliate->id)[$affiliate->id]['payable_minor']);
        $this->assertSame(4000, app(AffiliateLedger::class)->balances($affiliate->id)[$affiliate->id]['paid_minor']);
    }

    // --- Permissions and isolation (§78, §111) ---

    public function test_staff_see_only_what_their_role_allows_and_never_another_store(): void
    {
        [$store, $owner] = $this->store();
        $code = $this->runningProgramWithAffiliate($owner);
        $affiliate = Affiliate::query()->withoutTenantScope()->where('code', $code)->firstOrFail();
        $manager = User::factory()->create();
        $store->users()->attach($manager, ['role_id' => $this->systemRole($store, 'manager')->id, 'status' => 'active']);
        $staff = User::factory()->create();
        $store->users()->attach($staff, ['role_id' => $this->systemRole($store, 'staff')->id, 'status' => 'active']);

        $this->as($staff)->getJson('/api/v1/affiliates/overview')->assertForbidden();
        $this->as($manager)->getJson('/api/v1/affiliates')->assertOk()->assertJsonPath('data.0.code', $code);
        $this->as($manager)->postJson("/api/v1/affiliates/{$affiliate->public_id}/payouts")->assertForbidden();
        $this->as($manager)->putJson("/api/v1/affiliates/{$affiliate->public_id}/payout-details", ['method' => 'jazzcash', 'account_title' => 'X', 'account_number' => '0300'])->assertForbidden();

        [, $otherOwner] = $this->store();
        $this->as($otherOwner)->getJson('/api/v1/affiliates')->assertOk()->assertJsonCount(0, 'data');
        $this->as($otherOwner)->postJson("/api/v1/affiliates/{$affiliate->public_id}/pause")->assertNotFound();
    }

    public function test_a_store_without_the_package_feature_cannot_run_a_program(): void
    {
        [$store, $owner] = $this->store(affiliates: false);
        $this->as($owner)->putJson('/api/v1/affiliates/program', $this->rules())->assertOk();
        $this->as($owner)->postJson('/api/v1/affiliates/program/activate')->assertUnprocessable()->assertJsonPath('code', 'feature_not_entitled');
        $this->as($owner)->putJson('/api/v1/affiliates/program', $this->rules(['rate_percent' => 80]))->assertUnprocessable()->assertJsonValidationErrors('rate_percent');
        $this->as($owner)->putJson('/api/v1/affiliates/program', $this->rules(['window_days' => 365]))->assertUnprocessable()->assertJsonValidationErrors('window_days');
    }

    // --- Applications and the portal (§43, §91) ---

    public function test_applying_approval_and_the_private_portal_without_any_customer_data(): void
    {
        [$store, $owner, $product, $pickup] = $this->store();
        $this->runningProgramWithAffiliate($owner);
        $headers = ['X-Store-Slug' => $store->slug];
        $this->app['auth']->forgetGuards();
        $this->withoutVite()->get("/shop/{$store->slug}/affiliates/join")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Storefront/AffiliateJoin')->where('program.summary.0', 'You earn 10% of the products in each order you bring, after discounts. Shipping and tax do not count.'));
        $this->postJson('/api/v1/storefront/affiliates/apply', ['name' => 'Hira', 'email' => 'hira@example.com', 'accept_terms' => true], $headers)->assertStatus(202);
        $this->postJson('/api/v1/storefront/affiliates/apply', ['name' => 'Hira again', 'email' => 'HIRA@example.com', 'accept_terms' => true], $headers)->assertStatus(202);
        $this->postJson('/api/v1/storefront/affiliates/apply', ['name' => 'Bot', 'email' => 'bot@example.com', 'accept_terms' => true, 'website' => 'spam'], $headers)->assertUnprocessable();
        $hira = Affiliate::query()->withoutTenantScope()->where('email', 'hira@example.com')->sole();
        $this->assertSame(['pending', 'applied', 1], [$hira->status, $hira->source, $hira->terms_version]);
        $this->assertNull($this->token($store, $hira->code), 'a pending application earns nothing');

        $url = (string) $this->as($owner)->postJson("/api/v1/affiliates/{$hira->public_id}/approve")->assertOk()->json('data.portal_url');
        $token = (string) substr((string) strstr($url, '#token='), 7);
        $this->assertTrue(DB::table('notification_messages')->where('destination', 'hira@example.com')->where('body', 'like', '%[hidden]%')->exists(), 'the link is emailed but never stored readable');

        $this->checkout($store, $product, $pickup, $this->token($store, $hira->code), 'buyer@example.com');
        $this->app['auth']->forgetGuards();
        $portal = $this->getJson('/api/v1/storefront/affiliate', [...$headers, 'X-Affiliate-Token' => $token])->assertOk()->json('data');
        $this->assertSame($hira->code, $portal['code']);
        $this->assertStringEndsWith('/?ref='.$hira->code, $portal['link']);
        $this->assertSame(['date', 'sale_minor', 'commission_minor', 'status', 'source'], array_keys($portal['commissions'][0]));
        $this->assertStringNotContainsString('buyer@example.com', json_encode($portal));

        $this->putJson('/api/v1/storefront/affiliate/payout-details', ['method' => 'bank_transfer', 'account_title' => 'Hira K', 'account_number' => 'PK36 SCBL 0000 0011 2345 6702', 'bank' => 'Example Bank'], [...$headers, 'X-Affiliate-Token' => $token])
            ->assertOk()->assertJsonPath('data.account', str_repeat('•', 20).'6702');
        $this->as($owner)->postJson("/api/v1/affiliates/{$hira->public_id}/portal-link")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/storefront/affiliate', [...$headers, 'X-Affiliate-Token' => $token])->assertNotFound();
    }

    public function test_rules_are_versioned_and_commissions_keep_the_version_they_were_earned_under(): void
    {
        [$store, $owner, $product, $pickup] = $this->store();
        $code = $this->runningProgramWithAffiliate($owner);
        $before = $this->checkout($store, $product, $pickup, $this->token($store, $code));
        $this->as($owner)->putJson('/api/v1/affiliates/program', $this->rules(['commission_type' => 'fixed', 'fixed_minor' => 700]))->assertOk()->assertJsonPath('data.version', 2);
        $after = $this->checkout($store, $product, $pickup, $this->token($store, $code), 'z@example.com', 'k2');

        $this->assertSame([2000, 1], [AffiliateCommission::query()->withoutTenantScope()->where('order_id', $before->id)->value('amount_minor'), AffiliateCommission::query()->withoutTenantScope()->where('order_id', $before->id)->first()->snapshot['program_version']]);
        $this->assertSame([700, 2], [AffiliateCommission::query()->withoutTenantScope()->where('order_id', $after->id)->value('amount_minor'), AffiliateCommission::query()->withoutTenantScope()->where('order_id', $after->id)->first()->snapshot['program_version']]);
        $this->assertSame(2, DB::table('affiliate_program_versions')->count());
        $this->assertSame(250, AffiliateRules::commission([...AffiliateRules::DEFAULTS, 'rate_bp' => 1000], 2509), 'never a float: rounded down');
        $this->assertSame(300, AffiliateRules::commission([...AffiliateRules::DEFAULTS, 'commission_type' => 'fixed', 'fixed_minor' => 700], 300), 'never more than the sale');
        $this->assertNotEmpty(AffiliateProgramService::summary(AffiliateRules::DEFAULTS, 'PKR'));
    }
}
