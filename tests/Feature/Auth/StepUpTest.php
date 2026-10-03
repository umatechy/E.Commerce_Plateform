<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase B29 (gap G2) — step-up authentication: a sensitive action needs
 * a recent proof of identity (Module 30 §6, Module 32 §65.5; SRS SA-004).
 */
final class StepUpTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();

        config(['security.step_up.enabled' => true]);
        $this->withHeader('Referer', 'http://localhost'); // a browser session (ADR-002 Surface A)
    }

    private function staff(): User
    {
        return User::factory()->create(['password' => self::PASSWORD, 'platform_role' => 'support_agent']);
    }

    private function impersonate(Store $store): \Illuminate\Testing\TestResponse
    {
        return $this->getJson("/api/v1/super-admin/stores/{$store->id}/impersonate?reason=Support+request+4411");
    }

    public function test_impersonation_needs_a_recent_reauthentication(): void
    {
        $store = Store::factory()->create();

        // Signed in some other way, long ago: no recent proof of identity.
        $this->actingAs($this->staff());
        $this->impersonate($store)->assertStatus(403)->assertJsonPath('code', 'step_up_required');
        // Refused before the impersonation began: nothing about it in the audit trail.
        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'super_admin.%impersonat%')->count());

        $this->postJson('/api/v1/auth/step-up', ['password' => 'wrong'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->impersonate($store)->assertStatus(403);
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.password_confirmation.failed')->count());

        $this->postJson('/api/v1/auth/step-up', ['password' => self::PASSWORD])->assertOk()->assertJsonPath('data.valid_for_minutes', 15);
        $this->impersonate($store)->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.step_up.passed')->count());
    }

    public function test_a_step_up_expires(): void
    {
        $store = Store::factory()->create();
        $this->actingAs($this->staff());

        $this->postJson('/api/v1/auth/step-up', ['password' => self::PASSWORD])->assertOk();
        $this->impersonate($store)->assertOk();

        $this->travel(16)->minutes();
        $this->impersonate($store)->assertStatus(403)->assertJsonPath('code', 'step_up_required');
    }

    public function test_a_fresh_sign_in_counts_as_a_step_up(): void
    {
        $store = Store::factory()->create();
        $staff = $this->staff();

        $this->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => self::PASSWORD])->assertOk();
        $this->impersonate($store)->assertOk();
    }

    public function test_a_request_without_a_session_can_never_pass(): void
    {
        $store = Store::factory()->create();
        $this->withHeader('Referer', 'https://elsewhere.example'); // not the admin app: no session

        $this->actingAs($this->staff());
        $this->postJson('/api/v1/auth/step-up', ['password' => self::PASSWORD])->assertStatus(409)->assertJsonPath('code', 'session_required');
        $this->impersonate($store)->assertStatus(403)->assertJsonPath('code', 'step_up_required');
    }

    public function test_an_ordinary_read_needs_no_step_up(): void
    {
        $this->actingAs($this->staff())->getJson('/api/v1/super-admin/stores')->assertOk();
    }

    public function test_the_sensitive_routes_carry_the_step_up_check(): void
    {
        $guarded = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('step_up', $route->gatherMiddleware(), true))
            ->map(fn ($route) => $route->methods()[0].' '.$route->uri())
            ->sort()->values()->all();

        // Module 30 §6: impersonate, restore backup, change billing, change
        // platform pricing, change configuration, suspend.
        $this->assertSame([
            'GET api/v1/super-admin/stores/{store}/impersonate',
            'PATCH api/v1/super-admin/billing/prices/{price}',
            'POST api/v1/backups/{backup}/restore-request',
            // Phase B32: erasing a customer's personal data cannot be undone.
            'POST api/v1/customers/{customer}/erase',
            'POST api/v1/super-admin/billing/invoices/{invoice}/extend-due-date',
            'POST api/v1/super-admin/billing/invoices/{invoice}/payments',
            'POST api/v1/super-admin/billing/invoices/{invoice}/void',
            'POST api/v1/super-admin/billing/prices',
            'POST api/v1/super-admin/developer/applications/{application}/suspend',
            'POST api/v1/super-admin/packages',
            'POST api/v1/super-admin/restore-jobs/{backupRestoreJob}/authorize',
            'POST api/v1/super-admin/settings/revisions/{revisionId}/rollback',
            'POST api/v1/super-admin/users/{user}/deactivate',
            'POST api/v1/super-admin/users/{user}/reactivate',
            'PUT api/v1/super-admin/packages/{package}',
            'PUT api/v1/super-admin/packages/{package}/entitlements',
            'PUT api/v1/super-admin/settings/{key}',
        ], $guarded);
    }
}
