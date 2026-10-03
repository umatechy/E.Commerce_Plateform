<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Domain\Cart\Models\WishlistItem;
use App\Domain\Catalog\Models\Product;
use App\Domain\Compliance\Models\AuditLog;
use App\Domain\CustomerAccount\Models\CustomerAddress;
use App\Domain\Customers\Models\CustomerGroup;
use App\Domain\Customers\Models\CustomerMerge;
use App\Domain\Customers\Models\CustomerStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\CampaignRecipient;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Owner decision 2026-10-03 (Module 10 §87): groups, tags, import,
 * export and merge are Business and Premium (`customers.advanced`);
 * Basic keeps the rest. And Module 10 §56: the customer merge.
 */
final class CustomerMergeAndPackageTest extends TestCase
{
    use RefreshDatabase;

    /** A store on one of the seeded packages, with its owner. @return array{0: Store, 1: User} */
    private function storeOn(string $code): array
    {
        $this->seed(PackageSeeder::class);
        $store = Store::factory()->create(['status' => 'active']);
        Subscription::factory()->for($store)->for(Package::query()->where('code', $code)->firstOrFail())->create(['status' => SubscriptionStatus::Active]);
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);
        app(TenantContext::class)->resolveToStore($store->id);

        return [$store, $owner];
    }

    private function customer(Store $store, array $attributes = []): Customer
    {
        return Customer::factory()->for($store)->create(['password' => null, ...$attributes]);
    }

    public function test_basic_keeps_the_core_but_not_groups_tags_import_export_or_merge(): void
    {
        [$store, $owner] = $this->storeOn('basic');
        $ayesha = $this->customer($store, ['email' => 'ayesha@example.com']);
        $other = $this->customer($store, ['email' => 'other@example.com']);
        // A group made while the store was on a bigger package (a downgrade deletes nothing).
        $group = CustomerGroup::query()->create(['name' => 'Wholesale']);
        $ayesha->forceFill(['customer_group_id' => $group->id])->save();

        $this->actingAs($owner)->getJson('/api/v1/customers')->assertOk();
        $this->actingAs($owner)->getJson("/api/v1/customers/{$ayesha->public_id}")->assertOk()->assertJsonPath('data.group.name', 'Wholesale');
        $this->actingAs($owner)->getJson('/api/v1/customer-groups')->assertOk()->assertJsonPath('data.0.name', 'Wholesale');
        $this->actingAs($owner)->postJson('/api/v1/customers', ['name' => 'Walk In', 'email' => 'walkin@example.com'])->assertCreated();
        $this->actingAs($owner)->postJson("/api/v1/customers/{$ayesha->public_id}/notes", ['body' => 'Call first'])->assertCreated();
        $this->actingAs($owner)->postJson("/api/v1/customers/{$other->public_id}/block", ['reason' => 'Abuse'])->assertOk();

        $refused = [
            $this->actingAs($owner)->postJson('/api/v1/customer-groups', ['name' => 'Retail']),
            $this->actingAs($owner)->deleteJson("/api/v1/customer-groups/{$group->public_id}"),
            $this->actingAs($owner)->putJson("/api/v1/customers/{$ayesha->public_id}/tags", ['tags' => ['VIP']]),
            $this->actingAs($owner)->patchJson("/api/v1/customers/{$ayesha->public_id}", ['group' => null]),
            $this->actingAs($owner)->postJson('/api/v1/customers', ['name' => 'B', 'email' => 'b@example.com', 'tags' => ['VIP']]),
            $this->actingAs($owner)->getJson('/api/v1/customers/export'),
            $this->actingAs($owner)->post('/api/v1/customers/import', ['file' => UploadedFile::fake()->createWithContent('c.csv', "name,email\nA,a@example.com\n")], ['Accept' => 'application/json']),
            $this->actingAs($owner)->postJson("/api/v1/customers/{$ayesha->public_id}/merge", ['into' => $other->public_id, 'confirm_email' => 'other@example.com', 'reason' => 'x']),
        ];
        foreach ($refused as $i => $response) {
            $this->assertSame(403, $response->status(), "request #{$i} was not refused");
            $this->assertSame('feature_not_entitled', $response->json('code'), "request #{$i}");
        }

        // Nothing was changed by the refused calls.
        $this->assertSame($group->id, $ayesha->fresh()->customer_group_id);
        $this->assertSame(1, CustomerGroup::query()->count());
    }

    public function test_business_and_premium_include_them(): void
    {
        foreach (['business', 'premium'] as $code) {
            [$store, $owner] = $this->storeOn($code);
            $this->actingAs($owner)->postJson('/api/v1/customer-groups', ['name' => "Retail {$code}"])->assertCreated();
            $this->actingAs($owner)->get('/api/v1/customers/export')->assertOk();
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_a_duplicate_record_is_merged_into_the_customer_who_stays(): void
    {
        [$store, $owner] = $this->storeOn('business');
        $target = $this->customer($store, ['name' => 'Ayesha Khan', 'email' => 'ayesha@example.com', 'password' => Hash::make('x-pass-1234'), 'marketing_email_opt_in' => false]);
        $source = $this->customer($store, ['name' => 'A. Khan', 'email' => 'AYESHA@example.com', 'phone' => '+923001234567', 'marketing_email_opt_in' => true]);

        $order = Order::factory()->create(['store_id' => $store->id, 'customer_id' => $source->id, 'status' => OrderStatus::Completed, 'grand_total_minor' => 5000]);
        CustomerAddress::query()->create(['customer_id' => $target->id, 'name' => 'Home', 'line1' => '1 Mall Road', 'city' => 'Lahore', 'country' => 'PK', 'is_default' => true]);
        $sourceAddress = CustomerAddress::query()->create(['customer_id' => $source->id, 'name' => 'Shop', 'line1' => '9 Canal Road', 'city' => 'Lahore', 'country' => 'PK', 'is_default' => true]);
        $this->actingAs($owner)->putJson("/api/v1/customers/{$target->public_id}/tags", ['tags' => ['VIP']])->assertOk();
        $this->actingAs($owner)->putJson("/api/v1/customers/{$source->public_id}/tags", ['tags' => ['vip', 'Wholesale']])->assertOk();
        $group = CustomerGroup::query()->create(['name' => 'Retail']);
        $source->forceFill(['customer_group_id' => $group->id])->save();
        $this->actingAs($owner)->postJson("/api/v1/customers/{$source->public_id}/notes", ['body' => 'Pays in cash'])->assertCreated();
        $shared = Product::factory()->create(['store_id' => $store->id]);
        $own = Product::factory()->create(['store_id' => $store->id]);
        WishlistItem::query()->create(['customer_id' => $target->id, 'product_id' => $shared->id]);
        WishlistItem::query()->create(['customer_id' => $source->id, 'product_id' => $shared->id]);
        WishlistItem::query()->create(['customer_id' => $source->id, 'product_id' => $own->id]);
        $campaign = Campaign::factory()->for($store)->create();
        CampaignRecipient::query()->create(['campaign_id' => $campaign->id, 'customer_id' => $source->id, 'status' => 'queued', 'queued_at' => now()]);
        NotificationMessage::factory()->create(['store_id' => $store->id, 'recipient_type' => 'customer', 'recipient_id' => $source->id]);

        // The target's email is typed as the confirmation.
        $this->actingAs($owner)->postJson("/api/v1/customers/{$source->public_id}/merge", ['into' => $target->public_id, 'confirm_email' => 'someone@else.com', 'reason' => 'Same person'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirm_email');

        $response = $this->actingAs($owner)->postJson("/api/v1/customers/{$source->public_id}/merge", ['into' => $target->public_id, 'confirm_email' => 'Ayesha@Example.com', 'reason' => 'Same person, two records'])->assertOk();
        $response->assertJsonPath('data.moved.orders', 1)->assertJsonPath('data.moved.addresses', 1)->assertJsonPath('data.moved.wishlist_items', 1);

        $this->assertSame($target->id, $order->fresh()->customer_id);
        $this->assertSame($target->id, $sourceAddress->fresh()->customer_id);
        $this->assertFalse((bool) $sourceAddress->fresh()->is_default, 'the target keeps its default address');
        $this->assertEqualsCanonicalizing(['VIP', 'Wholesale'], $target->fresh()->tags()->pluck('name')->all());
        $this->assertSame($group->id, $target->fresh()->customer_group_id);
        $this->assertSame(2, WishlistItem::query()->where('customer_id', $target->id)->count());
        $this->assertSame(1, $target->notes()->count());
        $this->assertSame($target->id, CampaignRecipient::query()->where('campaign_id', $campaign->id)->value('customer_id'));
        $this->assertSame(1, NotificationMessage::query()->where('recipient_id', $target->id)->count());

        // The one who stays keeps their own identity and consent.
        $target->refresh();
        $this->assertSame('ayesha@example.com', $target->email);
        $this->assertFalse((bool) $target->marketing_email_opt_in);
        $this->assertNotNull($target->password);

        // The source is archived and points to the target; nothing deleted.
        $source->refresh();
        $this->assertSame(CustomerStatus::Archived, $source->status);
        $this->assertSame($target->id, $source->merged_into_customer_id);
        $this->assertNotNull($source->merged_at);
        $record = CustomerMerge::query()->sole();
        $this->assertSame([$source->id, $target->id, $owner->id], [$record->source_customer_id, $record->target_customer_id, $record->actor_user_id]);
        $this->assertSame(1, AuditLog::query()->where('action', 'customer.merged_into')->where('subject_id', $source->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'customer.merged_from')->where('subject_id', $target->id)->count());

        $this->actingAs($owner)->getJson("/api/v1/customers/{$target->public_id}")->assertOk()
            ->assertJsonPath('data.merged_from.0.id', $source->public_id)
            ->assertJsonPath('data.possible_duplicates', []);
        $this->actingAs($owner)->getJson("/api/v1/customers/{$source->public_id}")->assertOk()
            ->assertJsonPath('data.merged', true)->assertJsonPath('data.merged_into.id', $target->public_id);
        $this->actingAs($owner)->getJson("/api/v1/customers/{$target->public_id}/activity")->assertOk();

        // A record is merged once.
        $this->actingAs($owner)->postJson("/api/v1/customers/{$source->public_id}/merge", ['into' => $target->public_id, 'confirm_email' => 'ayesha@example.com', 'reason' => 'again'])
            ->assertStatus(409)->assertJsonPath('code', 'already_merged');
        // ...and stays history: it cannot be restored or changed.
        $this->actingAs($owner)->postJson("/api/v1/customers/{$source->public_id}/reactivate")->assertStatus(409)->assertJsonPath('code', 'merged');
        $this->actingAs($owner)->postJson("/api/v1/customers/{$source->public_id}/notes", ['body' => 'x'])->assertStatus(409)->assertJsonPath('code', 'merged');
    }

    public function test_merges_that_would_lose_a_sign_in_a_block_or_cross_stores_are_refused(): void
    {
        [$store, $owner] = $this->storeOn('business');
        $account = $this->customer($store, ['email' => 'acc@example.com', 'password' => Hash::make('x-pass-1234')]);
        $account2 = $this->customer($store, ['email' => 'acc2@example.com', 'password' => Hash::make('x-pass-1234')]);
        $plain = $this->customer($store, ['email' => 'plain@example.com']);
        $blocked = $this->customer($store, ['email' => 'blocked@example.com', 'status' => 'blocked']);
        $theirs = Customer::factory()->for(Store::factory()->create())->create(['email' => 'theirs@example.com', 'password' => null]);
        $merge = fn (Customer $from, Customer $into) => $this->actingAs($owner)->postJson("/api/v1/customers/{$from->public_id}/merge", ['into' => $into->public_id, 'confirm_email' => $into->email, 'reason' => 'x']);

        $merge($account, $plain)->assertStatus(409)->assertJsonPath('code', 'source_registered');
        $merge($account, $account2)->assertStatus(409)->assertJsonPath('code', 'both_registered');
        $merge($plain, $blocked)->assertStatus(409)->assertJsonPath('code', 'blocked');
        $merge($blocked, $account)->assertStatus(409)->assertJsonPath('code', 'blocked');
        $merge($plain, $plain)->assertStatus(422)->assertJsonPath('code', 'same_customer');
        $merge($plain, $theirs)->assertUnprocessable()->assertJsonValidationErrors('into');
        $this->actingAs($owner)->postJson("/api/v1/customers/{$theirs->public_id}/merge", ['into' => $plain->public_id, 'confirm_email' => 'plain@example.com', 'reason' => 'x'])->assertNotFound();

        $this->assertSame(0, CustomerMerge::query()->withoutTenantScope()->count());
        $this->assertNull($plain->fresh()->merged_into_customer_id);
    }

    public function test_a_viewer_cannot_merge(): void
    {
        [$store] = $this->storeOn('business');
        $viewer = User::factory()->create();
        $store->users()->attach($viewer, ['role_id' => $this->systemRole($store, 'order-manager')->id, 'status' => 'active']);
        $a = $this->customer($store, ['email' => 'a@example.com']);
        $b = $this->customer($store, ['email' => 'b@example.com']);

        $this->actingAs($viewer)->postJson("/api/v1/customers/{$a->public_id}/merge", ['into' => $b->public_id, 'confirm_email' => 'b@example.com', 'reason' => 'x'])->assertForbidden();
        $this->assertNull($a->fresh()->merged_into_customer_id);
    }
}
