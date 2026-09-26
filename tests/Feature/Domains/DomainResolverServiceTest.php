<?php

declare(strict_types=1);

namespace Tests\Feature\Domains;

use App\Domain\Domains\Models\Domain;
use App\Domain\Domains\Models\DomainStatus;
use App\Domain\Domains\Services\DomainResolverService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B14 — Authoritative Host->Store resolution: never falls back
 * to another store, never trusts a malformed input, only Active
 * domains resolve traffic (Module 19 §19-21/§52, Non-Negotiable).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class DomainResolverServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_domain_resolves_to_the_correct_store(): void
    {
        $store = Store::factory()->create(['slug' => 'my-shop']);
        app(TenantContext::class)->resolveToStore($store->id);
        $domain = Domain::query()->where('store_id', $store->id)->firstOrFail(); // auto-created platform subdomain

        $resolved = app(DomainResolverService::class)->resolveHost($domain->normalized_hostname);

        $this->assertNotNull($resolved);
        $this->assertSame($store->id, $resolved->id);
    }

    public function test_unknown_hostname_resolves_to_null(): void
    {
        $resolved = app(DomainResolverService::class)->resolveHost('nobody-has-this.example.com');

        $this->assertNull($resolved);
    }

    public function test_suspended_domain_does_not_resolve_traffic(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $domain = Domain::factory()->for($store)->create(['status' => DomainStatus::Suspended, 'normalized_hostname' => 'suspended.example.com']);

        $resolved = app(DomainResolverService::class)->resolveHost('suspended.example.com');

        $this->assertNull($resolved);
    }

    public function test_removed_domain_never_resolves_to_its_former_store(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        Domain::factory()->for($store)->create(['status' => DomainStatus::Removed, 'normalized_hostname' => 'gone.example.com']);

        $resolved = app(DomainResolverService::class)->resolveHost('gone.example.com');

        $this->assertNull($resolved);
    }

    public function test_malformed_host_never_falls_back_to_any_store(): void
    {
        $resolved = app(DomainResolverService::class)->resolveHost('https://not-a-hostname/path');

        $this->assertNull($resolved);
    }

    public function test_a_hostname_verified_for_store_a_never_resolves_to_store_b(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($storeA->id);
        Domain::factory()->for($storeA)->create(['status' => DomainStatus::Active, 'normalized_hostname' => 'shared-looking.example.com']);

        $resolved = app(DomainResolverService::class)->resolveHost('shared-looking.example.com');

        $this->assertSame($storeA->id, $resolved->id);
        $this->assertNotSame($storeB->id, $resolved->id);
    }

    public function test_primary_domain_for_returns_only_the_active_primary(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        $primary = app(DomainResolverService::class)->primaryDomainFor($store->fresh());

        $this->assertNotNull($primary);
        $this->assertTrue($primary->is_primary);
    }
}
