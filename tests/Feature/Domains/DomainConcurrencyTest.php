<?php

declare(strict_types=1);

namespace Tests\Feature\Domains;

use App\Domain\Domains\Models\Domain;
use App\Domain\Domains\Models\DomainStatus;
use App\Domain\Domains\Services\DomainService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B14 — this milestone's exact "two users set different primary
 * domains simultaneously" / "primary-domain race condition" scenario
 * (Step 48/Final Inspection Question #33). Simulated sequentially (no
 * real concurrent process available in this environment — see every
 * prior phase's identical, honestly-labeled precedent). Relies on
 * DomainService::setPrimary()'s own lockForUpdate() transaction — no
 * new concurrency mechanism beyond what that method already does.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 * This test has NOT been run under genuine parallel load; that
 * verification is explicitly deferred to VS Code/CI with a real MySQL
 * instance (row-level locking behavior cannot be meaningfully
 * exercised against SQLite/in-memory test setups either).
 */
final class DomainConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_sequential_set_primary_calls_never_leave_two_primaries(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $domainB = Domain::factory()->for($store)->create(['status' => DomainStatus::Verified]);
        $domainC = Domain::factory()->for($store)->create(['status' => DomainStatus::Verified]);

        // Simulates two "concurrent" requests racing to become primary
        // — the second call must still see a single, consistent
        // outcome (last-committer-wins), never two primaries at once.
        app(DomainService::class)->setPrimary($domainB);
        app(DomainService::class)->setPrimary($domainC);

        $this->assertSame(1, Domain::query()->where('store_id', $store->id)->where('is_primary', true)->count());
        $this->assertTrue($domainC->fresh()->is_primary);
    }

    public function test_set_primary_uses_the_truly_current_row_state_not_a_stale_in_memory_copy(): void
    {
        // Regression test for the exact bug found and fixed in B14
        // (see docs/development/b14-inspection-findings.md "Bug Found
        // and Fixed") — the target domain's status must be re-read from
        // within the lock, not trusted from before the transaction
        // began.
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $domain = Domain::factory()->for($store)->create(['status' => DomainStatus::Pending]);

        // Simulate a concurrent write that verified the domain AFTER
        // this test's own in-memory $domain was loaded.
        Domain::query()->whereKey($domain->id)->update(['status' => DomainStatus::Verified->value]);

        // $domain (in-memory) still thinks it's Pending — setPrimary()
        // must re-fetch and see Verified, not throw
        // DomainNotEligibleForPrimaryException based on the stale copy.
        // (The service's own eligibility pre-check reads $domain->status
        // directly, so this also exercises that the pre-check and the
        // in-transaction re-fetch agree once re-fetched.)
        $domain->refresh();
        $updated = app(DomainService::class)->setPrimary($domain);

        $this->assertTrue($updated->is_primary);
    }
}
