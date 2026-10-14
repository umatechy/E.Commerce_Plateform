<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Events\Jobs\ConsumeOutboxEventJob;
use App\Domain\Events\Models\OutboxEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Orders\Models\Order;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreDeletion;
use App\Domain\Tenancy\Models\StoreStatus;
use App\Domain\Tenancy\Services\StorePurger;
use App\Domain\Tenancy\Support\StoreDataMap;
use App\Domain\Tenancy\Support\TenantContext;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\DataProtection\InteractsWithBackups;
use Tests\TestCase;

/**
 * Phase B51 — gap G14; SRS STORE-003, STORE-009, MT-017; Module 03 §7–8,
 * §33–37; Module 30 §10, §140; owner decision 5 (90 days). Closing,
 * reopening, suspending, archiving and permanently deleting a store.
 */
final class StoreLifecycleTest extends TestCase
{
    use InteractsWithBackups, RefreshDatabase;

    private Store $store;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpBackups();
        Storage::fake('public');
        $this->withoutVite();
        $this->seed(PackageSeeder::class);
        [$this->store, $this->owner] = $this->demoStore('lifecycle', 'Rose House');
        $this->admin = User::factory()->create(['platform_role' => 'super_admin']);
    }

    /** @return array{0: Store, 1: User} */
    private function demoStore(string $key, string $name): array
    {
        $this->artisan('demo:store', ['--email' => "{$key}.owner@example.com", '--customer-email' => "{$key}.customer@example.com", '--name' => $name, '--no-pictures' => true])->assertSuccessful();

        return [Store::query()->where('name', $name)->sole(), User::query()->where('email', "{$key}.owner@example.com")->sole()];
    }

    private function as(User $user, ?Store $store = null): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->app->forgetInstance(TenantContext::class);
        session(['active_store_id' => ($store ?? $this->store)->id]);

        return $this->actingAs($user);
    }

    private function order(?Store $store = null, ?User $owner = null): Order
    {
        $store ??= $this->store;
        app(TenantContext::class)->resolveToStore($store->id);
        $product = \App\Domain\Catalog\Models\Product::query()->firstOrFail();
        $id = $this->as($owner ?? $this->owner, $store)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'guest_name' => 'Ayesha', 'guest_email' => 'ayesha'.uniqid().'@example.com',
            'guest_phone' => '03001234567', 'payment_method' => 'cod', 'source' => 'admin', 'idempotency_key' => 'o-'.uniqid(),
        ])->assertCreated()->json('data.id');

        return Order::query()->withoutGlobalScopes()->where('public_id', $id)->sole();
    }

    private function deliverEvents(): void
    {
        OutboxEvent::query()->withoutTenantScope()->where('status', 'pending')->orderBy('id')->pluck('id')
            ->each(fn (int $id) => app()->call([new ConsumeOutboxEventJob($id), 'handle']));
    }

    private function close(): void
    {
        $this->as($this->owner)->postJson('/api/v1/store-lifecycle/close', ['store_name' => 'Rose House', 'reason' => 'not_selling'])->assertOk();
    }

    public function test_only_the_owner_closes_with_the_store_name_and_the_store_turns_read_only_for_90_days(): void
    {
        $this->order();
        $staff = User::factory()->create();
        $this->store->users()->attach($staff, ['role_id' => $this->systemRole($this->store, 'administrator')->id, 'status' => 'active']);

        $this->as($this->owner)->getJson('/api/v1/store-lifecycle')->assertOk()->assertJsonPath('data.can_close', true)->assertJsonPath('data.retention_days', 90)
            ->assertJsonPath('data.open_items.0.key', 'orders');
        $this->as($staff)->getJson('/api/v1/store-lifecycle')->assertOk()->assertJsonPath('data.is_owner', false)->assertJsonMissingPath('data.open_items');
        $this->as($staff)->postJson('/api/v1/store-lifecycle/close', ['store_name' => 'Rose House'])->assertForbidden();
        $this->as($this->owner)->postJson('/api/v1/store-lifecycle/close', ['store_name' => 'Rose Hous'])->assertStatus(422)->assertJsonValidationErrors('store_name');
        $this->close();

        $store = $this->store->fresh();
        $this->assertSame(StoreStatus::Cancelled, $store->status);
        $this->assertSame(90, (int) round(now()->diffInDays($store->retention_ends_at)));
        $this->assertSame(SubscriptionStatus::Cancelled, Subscription::query()->withoutTenantScope()->where('store_id', $store->id)->sole()->status);

        // The shop is closed, the admin is read-only, but billing, support and the data stay reachable.
        $this->getJson('/api/v1/storefront/products', ['X-Store-Slug' => $store->slug])->assertStatus(503)->assertJsonPath('code', 'storefront_closed')->assertHeaderMissing('Retry-After');
        $this->as($this->owner)->postJson('/api/v1/categories', ['name' => 'New'])->assertStatus(423)->assertJsonPath('code', 'store_closed');
        $this->as($this->owner)->getJson('/api/v1/orders')->assertOk();
        $this->as($this->owner)->getJson('/api/v1/billing')->assertOk();
        $this->assertNotSame(423, $this->as($this->owner)->postJson('/api/v1/platform-support/tickets', ['subject' => 'Closing questions', 'message' => 'How do I get my invoices?', 'category' => 'billing'])->status());
        $csv = $this->as($this->owner)->get('/api/v1/store-lifecycle/export/orders')->assertOk()->streamedContent();
        $this->assertStringContainsString('Ayesha', $csv);
        $this->as($staff)->get('/api/v1/store-lifecycle/export/customers')->assertForbidden();

        // The owner is told, with the date; the history and the audit trail say who and why.
        $this->deliverEvents();
        $mail = NotificationMessage::query()->withoutGlobalScopes()->where('destination', 'lifecycle.owner@example.com')->where('source_event_type', 'store.lifecycle_changed')->sole();
        $this->assertStringContainsString($store->retention_ends_at->toFormattedDateString(), $mail->body);
        $this->assertDatabaseHas('store_lifecycle_events', ['store_id' => $store->id, 'action' => 'close', 'from_status' => 'active', 'to_status' => 'cancelled', 'reason' => 'not_selling', 'actor_type' => 'owner']);
        $this->assertDatabaseHas('audit_logs', ['store_id' => $store->id, 'action' => 'store.close']);
    }

    public function test_the_owner_reopens_within_the_retention_period_and_billing_restarts(): void
    {
        $this->close();
        $this->as($this->owner)->postJson('/api/v1/store-lifecycle/reopen', ['store_name' => 'rose house'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertSame(SubscriptionStatus::Active, Subscription::query()->withoutTenantScope()->where('store_id', $this->store->id)->sole()->status);
        $this->as($this->owner)->postJson('/api/v1/categories', ['name' => 'Back again'])->assertCreated();

        $this->close();
        $this->travel(91)->days();
        $this->as($this->owner)->postJson('/api/v1/store-lifecycle/reopen', ['store_name' => 'Rose House'])->assertStatus(422)->assertJsonPath('code', 'retention_over');
    }

    public function test_umar_techy_suspends_with_a_reason_and_reactivation_checks_the_subscription(): void
    {
        $base = "/api/v1/super-admin/stores/{$this->store->id}/lifecycle";
        $this->as($this->admin)->postJson("{$base}/suspend", ['reason' => 'made_up', 'note' => 'Reported'])->assertStatus(422);
        $this->as($this->admin)->postJson("{$base}/suspend", ['reason' => 'abuse', 'note' => 'Selling counterfeit goods, ticket 4411'])->assertOk()
            ->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.allowed', ['close', 'reactivate', 'archive']);
        $this->as($this->owner)->postJson('/api/v1/categories', ['name' => 'X'])->assertStatus(423)->assertJsonPath('code', 'store_suspended');
        $this->getJson('/api/v1/storefront/products', ['X-Store-Slug' => $this->store->slug])->assertStatus(503)->assertJsonPath('code', 'storefront_unavailable');

        app(\App\Domain\Packages\Services\SubscriptionLifecycleService::class)->suspend($this->store, 'billing');
        $this->as($this->admin)->postJson("{$base}/reactivate", ['note' => 'Goods removed, cleared'])->assertStatus(422)->assertJsonPath('code', 'subscription_inactive');
        $this->as($this->admin)->postJson("{$base}/reactivate", ['note' => 'Goods removed, cleared', 'also_subscription' => true])->assertOk()->assertJsonPath('data.status', 'active');
        $this->as($this->owner)->postJson('/api/v1/categories', ['name' => 'Allowed again'])->assertCreated();
        $this->as($this->owner)->postJson('/api/v1/store-lifecycle/close', ['store_name' => 'Rose House'])->assertOk();
        $this->as($this->admin)->getJson($base)->assertOk()->assertJsonPath('data.history.0.action', 'close')->assertJsonPath('data.history.1.action', 'reactivate');
    }

    public function test_closed_stores_are_reminded_once_then_archived_and_suspended_ones_wait_for_umar_techy(): void
    {
        $this->close();
        [$other, $otherOwner] = $this->demoStore('second', 'Second Shop');
        $this->as($this->admin, $other)->postJson("/api/v1/super-admin/stores/{$other->id}/lifecycle/suspend", ['reason' => 'policy', 'note' => 'Policy review'])->assertOk();

        $this->travel(80)->days();
        $this->artisan('stores:lifecycle')->assertSuccessful();
        $this->artisan('stores:lifecycle')->assertSuccessful();
        $this->deliverEvents();
        $this->assertSame(1, NotificationMessage::query()->withoutGlobalScopes()->where('destination', 'lifecycle.owner@example.com')->where('subject', 'like', 'Reminder:%')->count());

        $this->travel(11)->days();
        $this->artisan('stores:lifecycle')->assertSuccessful();
        $this->assertSame(StoreStatus::Archived, $this->store->fresh()->status);
        $this->assertSame(StoreStatus::Suspended, $other->fresh()->status, 'a suspension is never archived by itself');

        // Archived: the store's data is no longer shown or downloadable; billing and support still are.
        $this->as($this->owner)->getJson('/api/v1/orders')->assertStatus(423)->assertJsonPath('code', 'store_archived');
        $this->as($this->owner)->get('/api/v1/store-lifecycle/export/orders')->assertStatus(423);
        $this->as($this->owner)->getJson('/api/v1/billing')->assertOk();
        $this->as($this->owner)->postJson('/api/v1/store-lifecycle/reopen', ['store_name' => 'Rose House'])->assertStatus(422)->assertJsonPath('code', 'not_allowed');

        // Umar Techy can bring it back for another retention period.
        $this->as($this->admin)->postJson("/api/v1/super-admin/stores/{$this->store->id}/lifecycle/restore", ['note' => 'Owner asked for invoices'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertNotNull($otherOwner->id);
    }

    public function test_permanent_deletion_waits_for_checks_cooling_and_a_verified_backup_then_removes_only_this_stores_data(): void
    {
        $order = $this->order();
        [$other, $otherOwner] = $this->demoStore('second', 'Second Shop');
        $otherOrder = $this->order($other, $otherOwner);
        Storage::disk('public')->put("stores/{$this->store->public_id}/media/logo.png", 'png');
        Storage::disk('public')->put("stores/{$other->public_id}/media/logo.png", 'png');
        $base = "/api/v1/super-admin/stores/{$this->store->id}/lifecycle";

        $this->close();
        $this->as($this->admin)->postJson("{$base}/deletion", ['reason' => 'Owner asked for deletion by email', 'confirm_slug' => $this->store->slug])->assertStatus(422)->assertJsonPath('code', 'not_allowed');
        $this->travel(91)->days();
        $this->artisan('stores:lifecycle')->assertSuccessful();
        // An open Umar Techy invoice blocks the deletion until it is settled (Module 29 §160).
        $subscription = Subscription::query()->withoutTenantScope()->where('store_id', $this->store->id)->sole();
        $invoiceId = DB::table('invoices')->insertGetId(['public_id' => (string) \Illuminate\Support\Str::ulid(), 'number' => 'UT-TEST-0001', 'store_id' => $this->store->id,
            'subscription_id' => $subscription->id, 'package_id' => $subscription->package_id, 'status' => 'open', 'billing_reason' => 'renewal', 'currency' => 'PKR',
            'subtotal_minor' => 100000, 'tax_rate_bps' => 0, 'tax_minor' => 0, 'credit_applied_minor' => 0, 'total_minor' => 100000, 'amount_paid_minor' => 0, 'amount_credited_minor' => 0,
            'bill_to' => json_encode(['store' => 'Rose House']), 'period_start' => now()->subMonth(), 'period_end' => now(), 'issued_at' => now()->subMonth(), 'due_at' => now()->subWeeks(3),
            'created_at' => now(), 'updated_at' => now()]);

        $plan = $this->as($this->admin)->getJson("{$base}/deletion-plan")->assertOk();
        $this->assertGreaterThan(0, $plan->json('data.purge.products'));
        $this->assertContains('audit_logs', $plan->json('data.retained_tables'));
        $this->as($this->admin)->putJson("{$base}/legal-hold", ['hold' => true, 'reason' => 'Tax inquiry 2026-17'])->assertOk();
        $this->as($this->admin)->postJson("{$base}/deletion", ['reason' => 'Owner asked for deletion by email', 'confirm_slug' => $this->store->slug])->assertStatus(422)->assertJsonPath('code', 'legal_hold');
        $this->as($this->admin)->putJson("{$base}/legal-hold", ['hold' => false, 'reason' => 'Inquiry closed'])->assertOk();
        $this->as($this->admin)->postJson("{$base}/deletion", ['reason' => 'Owner asked for deletion by email', 'confirm_slug' => 'wrong'])->assertStatus(422)->assertJsonValidationErrors('confirm_slug');
        $this->as($this->admin)->postJson("{$base}/deletion", ['reason' => 'Owner asked for deletion by email', 'confirm_slug' => $this->store->slug])->assertStatus(422)->assertJsonPath('code', 'unpaid_invoices');
        DB::table('invoices')->where('id', $invoiceId)->update(['status' => 'uncollectible']); // written off by Umar Techy
        $this->as($this->admin)->postJson("{$base}/deletion", ['reason' => 'Owner asked for deletion by email', 'confirm_slug' => $this->store->slug])->assertOk()
            ->assertJsonPath('data.deletion.status', 'scheduled');

        // Nothing happens during the cooling period; it can still be cancelled — and requested again.
        $this->artisan('stores:lifecycle')->assertSuccessful();
        $this->assertSame(StoreDeletion::SCHEDULED, StoreDeletion::query()->sole()->status);
        $this->as($this->admin)->postJson("{$base}/deletion/cancel", ['note' => 'Owner changed their mind'])->assertOk()->assertJsonPath('data.deletion.status', 'cancelled');
        $this->as($this->admin)->postJson("{$base}/deletion", ['reason' => 'Owner confirmed deletion again', 'confirm_slug' => $this->store->slug])->assertOk();

        $this->travel(73)->hours();
        $this->artisan('stores:lifecycle')->assertSuccessful();

        $deletion = StoreDeletion::query()->where('status', '!=', 'cancelled')->sole();
        $this->assertSame(StoreDeletion::COMPLETED, $deletion->status, (string) $deletion->failure_message);
        $this->assertNotNull($deletion->backup_id);
        $this->assertSame('verified', DB::table('backups')->where('id', $deletion->backup_id)->value('status'));
        $this->assertSame([], $deletion->remaining);
        $this->assertGreaterThan(0, $deletion->removed['files']);

        $store = Store::query()->withTrashed()->findOrFail($this->store->id);
        $this->assertSame(StoreStatus::Deleted, $store->status);
        $this->assertTrue($store->trashed());
        foreach (StoreDataMap::PURGE as $table) {
            $this->assertSame(0, DB::table($table)->where('store_id', $store->id)->count(), "{$table} still has rows");
        }
        $this->assertFalse(Storage::disk('public')->exists("stores/{$store->public_id}/media/logo.png"));
        // Kept: billing, the audit trail, the lifecycle history; and the other store is untouched.
        $this->assertGreaterThan(0, DB::table('subscriptions')->where('store_id', $store->id)->count());
        $this->assertDatabaseHas('invoices', ['id' => $invoiceId, 'status' => 'uncollectible']);
        $this->assertGreaterThan(0, DB::table('audit_logs')->where('store_id', $store->id)->count());
        $this->assertDatabaseHas('store_lifecycle_events', ['store_id' => $store->id, 'action' => 'finish_deletion']);
        $this->assertDatabaseHas('orders', ['id' => $otherOrder->id]);
        $this->assertTrue(Storage::disk('public')->exists("stores/{$other->public_id}/media/logo.png"));
        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->as($this->admin)->getJson('/api/v1/super-admin/store-deletions')->assertOk()->assertJsonPath('data.0.status', 'completed')->assertJsonPath('data.0.store.name', 'Rose House');
    }

    public function test_a_failed_backup_stops_the_deletion_before_anything_is_removed_and_it_can_be_retried(): void
    {
        $this->close();
        $this->travel(91)->days();
        $this->artisan('stores:lifecycle')->assertSuccessful();
        $base = "/api/v1/super-admin/stores/{$this->store->id}/lifecycle";
        $this->as($this->admin)->postJson("{$base}/deletion", ['reason' => 'Owner asked for deletion by email', 'confirm_slug' => $this->store->slug])->assertOk();
        $products = DB::table('products')->where('store_id', $this->store->id)->count();
        $this->app->bind(\App\Domain\DataProtection\Services\DumpStrategies\DatabaseDumpStrategy::class, \Tests\Support\FailingDatabaseDumpStrategy::class);
        $this->travel(73)->hours();

        $this->artisan('stores:lifecycle')->assertSuccessful();

        $deletion = StoreDeletion::query()->sole();
        $this->assertSame(StoreDeletion::FAILED, $deletion->status);
        $this->assertNotNull($deletion->backup_id);
        $this->assertStringContainsString('nothing was removed', (string) $deletion->failure_message);
        $this->assertSame($products, DB::table('products')->where('store_id', $this->store->id)->count());
        $this->assertSame(StoreStatus::Archived, $this->store->fresh()->status);

        $this->app->bind(\App\Domain\DataProtection\Services\DumpStrategies\DatabaseDumpStrategy::class, \Tests\Support\FakeDatabaseDumpStrategy::class);
        $this->as($this->admin)->postJson("{$base}/deletion/retry")->assertOk()->assertJsonPath('data.deletion.status', 'scheduled');
        $this->artisan('stores:lifecycle')->assertSuccessful();
        $this->assertSame(StoreDeletion::COMPLETED, $deletion->fresh()->status);
        $this->assertSame(0, DB::table('products')->where('store_id', $this->store->id)->count());
    }

    public function test_every_store_table_has_a_deletion_decision_and_api_keys_stop_with_the_store(): void
    {
        $this->assertSame([], app(StorePurger::class)->unclassified(), 'add the new table to StoreDataMap::PURGE or ::RETAIN');
        $this->assertSame([], array_intersect(StoreDataMap::PURGE, StoreDataMap::RETAIN));

        app(TenantContext::class)->resolveToStore($this->store->id);
        $application = \App\Domain\DeveloperPlatform\Models\DeveloperApplication::factory()->for($this->store)->create(['status' => \App\Domain\DeveloperPlatform\Models\ApplicationStatus::Active]);
        $token = app(\App\Domain\DeveloperPlatform\Services\ApiKeyService::class)->issue($application, ['products:read'])['plaintext'];
        $this->getJson('/api/dev/v1/products', ['Authorization' => "Bearer {$token}"])->assertOk();
        $this->close();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/dev/v1/products', ['Authorization' => "Bearer {$token}"])->assertStatus(423)->assertJsonPath('code', 'store_not_operational');
    }
}
