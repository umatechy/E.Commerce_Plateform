<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Compliance\Services\AuditChainVerifier;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Compliance\Services\AuditRetentionService;
use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase B22 — Module 32 audit trail: who/where/what is derived
 * server-side, secrets never stored, and every chain is tamper-evident.
 */
final class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Store $store): User
    {
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);

        return $user;
    }

    private function chainFor(?Store $store): string
    {
        return $store === null ? 'platform' : "store:{$store->id}";
    }

    public function test_a_staff_action_records_actor_surface_store_and_request_details(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        app(TenantContext::class)->resolveToStore($store->id);
        $application = DeveloperApplication::factory()->for($store)->create();

        $this->actingAs($owner)
            ->withHeader('User-Agent', 'AuditTest/1.0')
            ->postJson("/api/v1/developer/applications/{$application->id}/keys", ['scopes' => ['products:read']])
            ->assertCreated();

        $entry = AuditLog::query()->where('action', 'developer.api_key.issued')->sole();
        $this->assertSame($store->id, $entry->store_id);
        $this->assertSame('user', $entry->actor_type->value);
        $this->assertSame($owner->public_id, $entry->actor_public_id);
        $this->assertSame($owner->email, $entry->actor_label);
        $this->assertSame('staff', $entry->surface->value);
        $this->assertSame('AuditTest/1.0', $entry->user_agent);
        $this->assertSame('127.0.0.1', $entry->ip_address);
        $this->assertSame(1, $entry->sequence);
    }

    public function test_secrets_are_redacted_and_never_stored(): void
    {
        $entry = app(AuditLogger::class)->record('test.redaction', [
            'email' => 'owner@example.com',
            'password' => 'hunter2-plain',
            'nested' => ['api_token' => 'tok_123', 'client_secret' => 's3cr3t', 'kept' => 'visible'],
        ]);

        $this->assertSame([
            'email' => 'owner@example.com',
            'nested' => ['api_token' => '[redacted]', 'client_secret' => '[redacted]', 'kept' => 'visible'],
            'password' => '[redacted]',
        ], $entry->contextData());
        $this->assertStringNotContainsString('hunter2', $entry->getRawOriginal('context'));
    }

    public function test_entries_are_immutable_through_the_model(): void
    {
        $entry = app(AuditLogger::class)->record('test.immutable');

        $this->expectException(\LogicException::class);
        $entry->update(['action' => 'rewritten']);
    }

    public function test_an_untouched_chain_verifies_and_links_every_entry(): void
    {
        $logger = app(AuditLogger::class);
        $first = $logger->record('test.one');
        $second = $logger->record('test.two');

        $this->assertNull($first->previous_hash);
        $this->assertSame($first->hash, $second->previous_hash);
        $this->assertSame(['chain' => 'platform', 'status' => 'ok', 'verified_entries' => 2, 'first_broken_sequence' => null, 'reason' => null], app(AuditChainVerifier::class)->verify('platform'));
    }

    public function test_an_edited_entry_breaks_the_chain(): void
    {
        $logger = app(AuditLogger::class);
        $logger->record('test.one', ['amount' => 100]);
        $edited = $logger->record('test.two', ['amount' => 200]);
        $logger->record('test.three');

        DB::table('audit_logs')->where('id', $edited->id)->update(['context' => '{"amount":1}']);

        $result = app(AuditChainVerifier::class)->verify('platform');
        $this->assertSame('broken', $result['status']);
        $this->assertSame(2, $result['first_broken_sequence']);
        $this->assertStringContainsString('modified', $result['reason']);
    }

    public function test_a_removed_entry_or_truncated_tail_breaks_the_chain(): void
    {
        $logger = app(AuditLogger::class);
        $logger->record('test.one');
        $middle = $logger->record('test.two');
        $last = $logger->record('test.three');

        DB::table('audit_logs')->where('id', $last->id)->delete();
        $this->assertSame('broken', app(AuditChainVerifier::class)->verify('platform')['status']);

        DB::table('audit_logs')->where('id', $middle->id)->delete();
        $result = app(AuditChainVerifier::class)->verify('platform');
        $this->assertSame('broken', $result['status']);
        $this->assertSame(2, $result['first_broken_sequence']);
    }

    public function test_each_store_has_its_own_chain(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $logger = app(AuditLogger::class);

        $logger->record('test.a', storeId: $storeA->id);
        $logger->record('test.b', storeId: $storeB->id);
        $second = $logger->record('test.a2', storeId: $storeA->id);

        $this->assertSame(2, $second->sequence);
        $this->assertSame('ok', app(AuditChainVerifier::class)->verify($this->chainFor($storeA))['status']);
        $this->assertSame(1, app(AuditChainVerifier::class)->verify($this->chainFor($storeB))['verified_entries']);
    }

    public function test_pruning_keeps_the_remaining_chain_verifiable(): void
    {
        config(['compliance.audit.retention_days' => 30]);
        $logger = app(AuditLogger::class);

        \Illuminate\Support\Carbon::setTestNow(now()->subDays(40));
        $logger->record('test.old');
        $logger->record('test.also_old');
        \Illuminate\Support\Carbon::setTestNow();
        $logger->record('test.recent');

        $this->assertSame(2, app(AuditRetentionService::class)->prune());
        $this->assertSame(['test.recent'], AuditLog::query()->pluck('action')->all());
        // The oldest kept entry links to the anchor left by pruning.
        $this->assertSame(['chain' => 'platform', 'status' => 'ok', 'verified_entries' => 1, 'first_broken_sequence' => null, 'reason' => null], app(AuditChainVerifier::class)->verify('platform'));
    }

    public function test_super_admin_impersonation_is_attributed_to_the_store_and_the_super_admin(): void
    {
        $store = Store::factory()->create();
        Subscription::factory()->for($store)->for(Package::factory())->create();
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $this->actingAs($superAdmin)
            ->postJson("/api/v1/super-admin/stores/{$store->id}/subscription/suspend", ['reason' => 'fraud_review'])
            ->assertNoContent();

        $entry = AuditLog::query()->where('action', 'super_admin.subscription.suspended')->sole();
        $this->assertSame($store->id, $entry->store_id);
        $this->assertSame('super_admin', $entry->surface->value);
        $this->assertSame($superAdmin->email, $entry->impersonator_label);
        $this->assertSame('store', $entry->subject_type);
        $this->assertSame($store->public_id, $entry->subject_public_id);
        $this->assertTrue(AuditLog::query()->where('store_id', $store->id)->where('action', 'super_admin.impersonation.started')->exists());
    }

    public function test_staff_login_success_and_failure_are_recorded_in_the_platform_chain_without_the_password(): void
    {
        $user = User::factory()->create(['email' => 'staff@example.com', 'password' => Hash::make('correct-horse-battery')]);

        $this->postJson('/api/v1/auth/login', ['email' => 'staff@example.com', 'password' => 'wrong-password-123'])->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['email' => 'staff@example.com', 'password' => 'correct-horse-battery'])->assertOk();

        $failed = AuditLog::query()->where('action', 'auth.login.failed')->sole();
        $this->assertNull($failed->store_id);
        $this->assertSame('anonymous', $failed->actor_type->value);
        $this->assertSame('staff@example.com', $failed->contextData()['email']);
        $this->assertStringNotContainsString('wrong-password', $failed->getRawOriginal('context'));

        $succeeded = AuditLog::query()->where('action', 'auth.login.succeeded')->sole();
        $this->assertSame($user->public_id, $succeeded->actor_public_id);
        $this->assertSame('platform', $succeeded->chain_key);
    }

    public function test_customer_logins_are_recorded_in_the_customers_store_chain(): void
    {
        $store = Store::factory()->create();
        Customer::factory()->for($store)->create(['email' => 'jane@example.com', 'password' => Hash::make('customer-pass-99')]);
        $headers = ['X-Store-Slug' => $store->slug];

        $this->postJson('/api/v1/customer/login', ['email' => 'jane@example.com', 'password' => 'nope-nope-nope'], $headers)->assertStatus(422);
        $this->postJson('/api/v1/customer/login', ['email' => 'jane@example.com', 'password' => 'customer-pass-99'], $headers)->assertOk();

        $this->assertSame(
            ['auth.login.failed', 'auth.login.succeeded'],
            AuditLog::query()->where('store_id', $store->id)->orderBy('sequence')->pluck('action')->all(),
        );
        $this->assertSame('customer', AuditLog::query()->where('action', 'auth.login.succeeded')->sole()->surface->value);
    }
}
