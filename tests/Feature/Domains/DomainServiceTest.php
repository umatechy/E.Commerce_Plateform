<?php

declare(strict_types=1);

namespace Tests\Feature\Domains;

use App\Domain\Domains\Exceptions\DomainNotEligibleForPrimaryException;
use App\Domain\Domains\Exceptions\InvalidDomainStateTransitionException;
use App\Domain\Domains\Models\Domain;
use App\Domain\Domains\Models\DomainStatus;
use App\Domain\Domains\Services\DomainService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B14 — Domain lifecycle, platform-subdomain auto-creation,
 * primary-domain switching atomicity (Module 19 §8/§17-19, Non-
 * Negotiable).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class DomainServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_store_automatically_gets_an_active_primary_platform_subdomain(): void
    {
        $store = Store::factory()->create(['slug' => 'my-shop']);

        $domain = Domain::query()->withoutTenantScope()->where('store_id', $store->id)->first();

        $this->assertNotNull($domain);
        $this->assertSame('active', $domain->status->value);
        $this->assertTrue($domain->is_primary);
        $this->assertStringContainsString('my-shop', $domain->normalized_hostname);
    }

    public function test_adding_a_custom_domain_starts_as_pending_and_not_primary(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        $domain = app(DomainService::class)->addCustomDomain($store, 'shop.mystore.com');

        $this->assertSame('pending', $domain->status->value);
        $this->assertFalse($domain->is_primary);
    }

    public function test_setting_primary_demotes_the_previous_primary(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $oldPrimary = Domain::query()->where('store_id', $store->id)->where('is_primary', true)->firstOrFail();
        $newDomain = Domain::factory()->for($store)->create(['status' => DomainStatus::Verified, 'is_primary' => false]);

        app(DomainService::class)->setPrimary($newDomain);

        $this->assertFalse($oldPrimary->fresh()->is_primary);
        $this->assertTrue($newDomain->fresh()->is_primary);
    }

    public function test_only_one_domain_is_ever_primary_per_store(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $domainB = Domain::factory()->for($store)->create(['status' => DomainStatus::Verified]);
        app(DomainService::class)->setPrimary($domainB);
        $domainC = Domain::factory()->for($store)->create(['status' => DomainStatus::Verified]);

        app(DomainService::class)->setPrimary($domainC);

        $this->assertSame(1, Domain::query()->where('store_id', $store->id)->where('is_primary', true)->count());
    }

    public function test_a_pending_domain_cannot_be_set_as_primary(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $domain = Domain::factory()->for($store)->create(['status' => DomainStatus::Pending]);

        $this->expectException(DomainNotEligibleForPrimaryException::class);
        app(DomainService::class)->setPrimary($domain);
    }

    public function test_removing_a_domain_preserves_the_historical_row(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $domain = Domain::factory()->for($store)->create(['status' => DomainStatus::Verified]);

        $removed = app(DomainService::class)->remove($domain);

        $this->assertSame('removed', $removed->status->value);
        $this->assertDatabaseHas('domains', ['id' => $domain->id, 'status' => 'removed']);
    }

    public function test_removed_domain_is_terminal(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $domain = Domain::factory()->for($store)->create(['status' => DomainStatus::Removed]);

        $this->expectException(InvalidDomainStateTransitionException::class);
        app(DomainService::class)->remove($domain);
    }
}
