<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Domain\Catalog\Models\Product;
use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Customers\Models\CustomerStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Payments\Models\Payment;
use App\Domain\Promotions\Models\Promotion;
use App\Domain\Promotions\Models\PromotionTargetScope;
use App\Domain\Settings\Models\SettingRevision;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Phase B32: the admin gaps closed with G7 — an admin order for a
 * customer with its payment, the settings scope fixes, package contents
 * editing, platform settings history, theme draft preview and promotion
 * target names.
 */
final class AdminGapsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(Store $store): User
    {
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);

        return $user;
    }

    /** @return array{0: Store, 1: User, 2: Product} */
    private function shop(): array
    {
        $store = Store::factory()->create(['status' => 'active']);
        $this->entitle($store, ['orders.basic', 'payment.cod']);
        $owner = $this->owner($store);
        $product = Product::factory()->for($store)->create(['price_minor' => 2500, 'currency' => 'USD']);
        $warehouse = Warehouse::query()->withoutTenantScope()->where('store_id', $store->id)->where('is_default', true)->firstOrFail();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['product_id' => $product->id]);
        app(TenantContext::class)->resolveToStore($store->id);
        app(InventoryService::class)->setOpeningStock($inventory, 50, 'Init', actorId: $owner->id, idempotencyKey: 'open-'.$store->id);

        return [$store, $owner, $product];
    }

    public function test_an_admin_order_for_a_customer_gets_its_payment(): void
    {
        [$store, $owner, $product] = $this->shop();
        $customer = Customer::factory()->for($store)->create(['name' => 'Ayesha', 'email' => 'ayesha@example.com']);

        $body = ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'customer' => $customer->public_id, 'payment_method' => 'cod', 'source' => 'admin', 'idempotency_key' => 'admin-order-1'];
        $response = $this->actingAs($owner)->postJson('/api/v1/orders', $body)->assertCreated();
        $response->assertJsonPath('data.customer.id', $customer->public_id);

        $order = Order::query()->where('public_id', $response->json('data.id'))->sole();
        $payment = Payment::query()->where('order_id', $order->id)->sole();
        $this->assertSame('cod', $payment->method->value);
        $this->assertSame($order->grand_total_minor, $payment->amount_minor);

        // A retry with the same key makes neither a second order nor a second payment.
        $this->actingAs($owner)->postJson('/api/v1/orders', $body)->assertOk();
        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count());

        $this->actingAs($owner)->getJson("/api/v1/orders?customer={$customer->public_id}")->assertOk()->assertJsonPath('data.0.id', $order->public_id);
    }

    public function test_an_admin_order_cannot_name_another_stores_customer_or_a_blocked_one(): void
    {
        [$store, $owner, $product] = $this->shop();
        $theirs = Customer::factory()->for(Store::factory()->create())->create();
        $blocked = Customer::factory()->for($store)->create(['email' => 'blocked@example.com']);
        $blocked->forceFill(['status' => CustomerStatus::Blocked])->save();
        $items = [['product_id' => $product->id, 'quantity' => 1]];

        $this->actingAs($owner)->postJson('/api/v1/orders', ['items' => $items, 'customer_id' => $theirs->id, 'idempotency_key' => 'x1'])->assertUnprocessable()->assertJsonValidationErrors('customer');
        $this->actingAs($owner)->postJson('/api/v1/orders', ['items' => $items, 'customer' => $theirs->public_id, 'idempotency_key' => 'x2'])->assertUnprocessable()->assertJsonValidationErrors('customer');
        $this->actingAs($owner)->postJson('/api/v1/orders', ['items' => $items, 'customer' => $blocked->public_id, 'idempotency_key' => 'x3'])->assertUnprocessable()->assertJsonValidationErrors('customer');
        $this->actingAs($owner)->postJson('/api/v1/orders', ['items' => $items, 'guest_name' => 'B', 'guest_email' => 'BLOCKED@example.com', 'idempotency_key' => 'x4'])->assertUnprocessable()->assertJsonValidationErrors('guest_email');
        // A payment method the package does not include.
        $this->actingAs($owner)->postJson('/api/v1/orders', ['items' => $items, 'guest_name' => 'G', 'guest_email' => 'g@example.com', 'payment_method' => 'bank_transfer', 'idempotency_key' => 'x5'])->assertForbidden();

        $this->assertSame(0, Order::query()->count());
    }

    public function test_store_settings_history_and_rollback_cannot_reach_platform_settings(): void
    {
        $store = Store::factory()->create();
        $owner = $this->owner($store);
        $platform = User::factory()->create(['platform_role' => 'support_agent']);

        $this->actingAs($platform)->putJson('/api/v1/super-admin/settings/alerts.critical_email_recipients', ['value' => ['ops@example.com']])->assertOk();
        $revision = SettingRevision::query()->where('key', 'alerts.critical_email_recipients')->latest('id')->firstOrFail();

        $this->app['auth']->forgetGuards();
        $this->actingAs($owner)->getJson('/api/v1/store/settings/alerts.critical_email_recipients/history')->assertNotFound();
        $this->actingAs($owner)->postJson("/api/v1/store/settings/revisions/{$revision->id}/rollback")->assertNotFound();
    }

    public function test_platform_settings_have_a_history_that_can_be_rolled_back(): void
    {
        $platform = User::factory()->create(['platform_role' => 'support_agent']);
        $this->actingAs($platform)->putJson('/api/v1/super-admin/settings/backup.retention_days', ['value' => 30])->assertOk();
        $first = SettingRevision::query()->where('key', 'backup.retention_days')->latest('id')->firstOrFail();
        $this->actingAs($platform)->putJson('/api/v1/super-admin/settings/backup.retention_days', ['value' => 45])->assertOk();

        $history = $this->actingAs($platform)->getJson('/api/v1/super-admin/settings/backup.retention_days/history')->assertOk();
        $this->assertGreaterThanOrEqual(2, count($history->json('data')));
        $this->actingAs($platform)->getJson('/api/v1/super-admin/settings/store.timezone/history')->assertNotFound();

        $this->actingAs($platform)->postJson("/api/v1/super-admin/settings/revisions/{$first->id}/rollback")->assertNoContent();
        $this->assertSame(30, app(\App\Domain\Settings\Services\ConfigService::class)->get('backup.retention_days'));
        $this->assertSame(1, AuditLog::query()->where('action', 'super_admin.setting.rolled_back')->count());

        // A store's revision is not a platform setting.
        $store = Store::factory()->create();
        $owner = $this->owner($store);
        $this->app['auth']->forgetGuards();
        $this->actingAs($owner)->putJson('/api/v1/store/settings/store.timezone', ['value' => 'Asia/Karachi'])->assertOk();
        $storeRevision = SettingRevision::query()->where('key', 'store.timezone')->latest('id')->firstOrFail();
        $this->app['auth']->forgetGuards();
        $this->actingAs($platform)->postJson("/api/v1/super-admin/settings/revisions/{$storeRevision->id}/rollback")->assertNotFound();
    }

    public function test_platform_staff_change_what_a_package_includes_with_a_reason_and_history(): void
    {
        $platform = User::factory()->create(['platform_role' => 'support_agent']);
        $basic = Package::factory()->create(['code' => 'basic-x']);
        $basic->entitlements()->create(['key' => 'payment.cod', 'type' => EntitlementType::Feature, 'boolean_value' => false]);
        $basic->entitlements()->create(['key' => 'max_products', 'type' => EntitlementType::UsageLimit, 'limit_value' => 50]);
        $premium = Package::factory()->create(['code' => 'premium-x']);
        $premium->entitlements()->create(['key' => 'payment.bank_transfer', 'type' => EntitlementType::Feature, 'boolean_value' => true]);

        $store = Store::factory()->create();
        \App\Domain\Packages\Models\Subscription::factory()->for($store)->for($basic)->create(['status' => \App\Domain\Packages\Models\SubscriptionStatus::Active]);
        Cache::put("tenant:{$store->id}:entitlement:payment.cod", false, 3600);

        $url = '/api/v1/super-admin/packages/basic-x/entitlements';
        $this->actingAs($platform)->putJson($url, ['entitlements' => [['key' => 'payment.cod', 'enabled' => true]]])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($platform)->putJson($url, ['entitlements' => [['key' => 'made.up', 'enabled' => true]], 'reason' => 'x'])->assertUnprocessable();
        $this->actingAs($platform)->putJson($url, ['entitlements' => [['key' => 'max_products']], 'reason' => 'x'])->assertUnprocessable();

        $this->actingAs($platform)->putJson($url, ['entitlements' => [
            ['key' => 'payment.cod', 'enabled' => true],
            ['key' => 'max_products', 'unlimited' => false, 'limit' => 200],
            // Known from another package: added to this one.
            ['key' => 'payment.bank_transfer', 'enabled' => true],
        ], 'reason' => 'Launch offer'])->assertOk();

        $rows = $basic->entitlements()->get()->keyBy('key');
        $this->assertTrue((bool) $rows['payment.cod']->boolean_value);
        $this->assertSame(200, (int) $rows['max_products']->limit_value);
        $this->assertTrue((bool) $rows['payment.bank_transfer']->boolean_value);
        $this->assertFalse(Cache::has("tenant:{$store->id}:entitlement:payment.cod"));

        $entry = AuditLog::query()->where('action', 'super_admin.package.entitlements_changed')->sole();
        $context = $entry->contextData();
        $this->assertSame('Launch offer', $context['reason']);
        $changes = collect($context['changes'])->keyBy('key');
        $this->assertSame(['not set', 'yes'], [$changes['payment.bank_transfer']['before'], $changes['payment.bank_transfer']['after']]);
        $this->assertSame(['50', '200'], [$changes['max_products']['before'], $changes['max_products']['after']]);
        $this->assertSame(['no', 'yes'], [$changes['payment.cod']['before'], $changes['payment.cod']['after']]);

        // Store staff cannot.
        $owner = $this->owner($store);
        $this->app['auth']->forgetGuards();
        $this->actingAs($owner)->putJson($url, ['entitlements' => [['key' => 'payment.cod', 'enabled' => false]], 'reason' => 'x'])->assertForbidden();
    }

    public function test_a_theme_preview_link_shows_the_draft_to_its_store_only_and_is_not_indexed(): void
    {
        $store = Store::factory()->create(['status' => 'active']);
        $other = Store::factory()->create(['status' => 'active']);
        // A storefront opens only for a store with a running subscription.
        $this->entitle($store, ['orders.basic']);
        $this->entitle($other, ['orders.basic']);
        $owner = $this->owner($store);
        $this->actingAs($owner)->putJson('/api/v1/store/theme/draft', ['config' => ['tokens' => ['primary' => '#FF00FF']]])->assertOk();

        $link = $this->actingAs($owner)->postJson('/api/v1/store/theme/preview')->assertOk()->json('data');
        $this->assertStringStartsWith("/shop/{$store->slug}/?theme_preview=", $link['url']);
        $this->assertSame(1, AuditLog::query()->where('action', 'theme.preview_link_created')->count());
        $token = urldecode(substr($link['url'], strpos($link['url'], '=') + 1));

        $this->app['auth']->forgetGuards();
        $this->withoutVite()->get($link['url'])->assertOk()->assertInertia(fn ($page) => $page
            ->where('storefront.theme.tokens.primary', '#FF00FF')
            ->where('seo.robots', 'noindex, nofollow')
            ->whereNot('storefront.theme_preview', null));

        // Without the link, customers see the published theme.
        $this->withoutVite()->withCookies([])->get("/shop/{$store->slug}")->assertOk()->assertInertia(fn ($page) => $page->where('storefront.theme_preview', null));
        // The token is for its store only, and cannot be edited.
        $this->withoutVite()->get("/shop/{$other->slug}/?theme_preview=".urlencode($token))->assertOk()->assertInertia(fn ($page) => $page->where('storefront.theme_preview', null));
        $this->withoutVite()->get("/shop/{$store->slug}/?theme_preview=".urlencode($token.'x'))->assertOk()->assertInertia(fn ($page) => $page->where('storefront.theme_preview', null));
    }

    public function test_promotions_name_their_targets(): void
    {
        $store = Store::factory()->create();
        $owner = $this->owner($store);
        app(TenantContext::class)->resolveToStore($store->id);
        $product = Product::factory()->for($store)->create(['name' => 'Rose Attar']);
        $promotion = Promotion::factory()->create(['store_id' => $store->id, 'target_scope' => PromotionTargetScope::Product]);
        $promotion->targets()->create(['store_id' => $store->id, 'target_type' => 'product', 'target_id' => $product->id]);
        $promotion->targets()->create(['store_id' => $store->id, 'target_type' => 'product', 'target_id' => 999999]);

        $show = $this->actingAs($owner)->getJson("/api/v1/promotions/{$promotion->public_id}")->assertOk();
        $targets = collect($show->json('data.targets'))->keyBy('id');
        $this->assertSame('Rose Attar', $targets[$product->id]['name']);
        $this->assertNull($targets[999999]['name']);

        $this->actingAs($owner)->getJson('/api/v1/promotions')->assertOk()->assertJsonPath('data.0.targets.0.name', 'Rose Attar');
    }
}
