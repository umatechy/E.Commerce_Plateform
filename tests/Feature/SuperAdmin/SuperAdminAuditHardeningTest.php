<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\Package;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B16 — regression test for the exact "Third Finding" fix in
 * docs/development/b16-inspection-findings.md: mutating Super Admin
 * actions must write an ACTION-SPECIFIC audit entry (before/after
 * state), not rely solely on the generic middleware-level log.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class SuperAdminAuditHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_suspension_writes_an_action_specific_audit_entry(): void
    {
        $store = Store::factory()->create();
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        \Illuminate\Support\Facades\Log::shouldReceive('channel')->with('audit')->andReturnSelf();
        \Illuminate\Support\Facades\Log::shouldReceive('info')->with('super_admin.impersonation.started', \Mockery::type('array'));
        \Illuminate\Support\Facades\Log::shouldReceive('info')
            ->once()
            ->with('super_admin.subscription.suspended', \Mockery::on(fn (array $c) => $c['store_id'] === $store->id && $c['reason'] === 'fraud_review'));

        $this->actingAs($superAdmin)->postJson("/api/v1/super-admin/stores/{$store->id}/subscription/suspend", ['reason' => 'fraud_review']);
    }

    public function test_package_update_writes_before_and_after_state(): void
    {
        $package = Package::factory()->create(['name' => 'Old Name']);
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        \Illuminate\Support\Facades\Log::shouldReceive('channel')->with('audit')->andReturnSelf();
        \Illuminate\Support\Facades\Log::shouldReceive('info')
            ->once()
            ->with('super_admin.package.updated', \Mockery::on(function (array $c) {
                return $c['before']['name'] === 'Old Name' && $c['after']['name'] === 'New Name';
            }));

        $this->actingAs($superAdmin)->putJson("/api/v1/super-admin/packages/{$package->id}", ['name' => 'New Name']);
    }
}
