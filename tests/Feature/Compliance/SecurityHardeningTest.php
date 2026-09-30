<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase B22 — Module 32 hardening that is not the audit trail itself:
 * security headers, the password policy, and the audit maintenance
 * commands the scheduler runs.
 */
final class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_responses_carry_the_baseline_security_headers_and_a_deny_all_csp(): void
    {
        $response = $this->getJson('/api/v1/public/health');

        $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        $this->assertStringContainsString('camera=()', $response->headers->get('Permissions-Policy'));
    }

    public function test_admin_pages_get_the_baseline_headers_but_no_api_csp(): void
    {
        $this->withoutVite()->get('/login')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_error_responses_carry_the_headers_too(): void
    {
        $this->getJson('/api/v1/audit-logs')
            ->assertUnauthorized()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_hsts_is_sent_only_over_https(): void
    {
        $this->getJson('/api/v1/public/health')->assertHeaderMissing('Strict-Transport-Security');

        $this->getJson('https://localhost/api/v1/public/health')
            ->assertHeader('Strict-Transport-Security', 'max-age='.config('compliance.security_headers.hsts_max_age').'; includeSubDomains');
    }

    public function test_the_password_policy_rejects_short_or_digit_only_passwords(): void
    {
        $store = Store::factory()->create();
        $headers = ['X-Store-Slug' => $store->slug];

        foreach (['short1', '12345678901234'] as $weak) {
            $this->postJson('/api/v1/customer/register', [
                'name' => 'Jane', 'email' => 'jane@example.com', 'password' => $weak, 'password_confirmation' => $weak,
            ], $headers)->assertStatus(422)->assertJsonValidationErrors('password');
        }

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'short1', 'password_confirmation' => 'short1', 'store_name' => 'Shop',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/customer/register', [
            'name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'long-enough-pass', 'password_confirmation' => 'long-enough-pass',
        ], $headers)->assertCreated();

        $this->assertTrue(AuditLog::query()->where('action', 'customer.registered')->where('store_id', $store->id)->exists());
    }

    public function test_the_verify_command_passes_on_an_intact_trail_and_fails_on_a_tampered_one(): void
    {
        $store = Store::factory()->create();
        app(AuditLogger::class)->record('orders.cancelled', storeId: $store->id);
        app(AuditLogger::class)->record('super_admin.package.updated');

        $this->artisan('audit:verify')->assertSuccessful();
        $this->artisan('audit:verify', ['--chain' => 'platform'])->assertSuccessful();

        DB::table('audit_logs')->where('store_id', $store->id)->update(['actor_label' => 'someone-else']);

        $this->artisan('audit:verify')->assertFailed();
        $this->artisan('audit:verify', ['--chain' => 'platform'])->assertSuccessful();
    }

    public function test_the_prune_command_removes_only_entries_past_retention(): void
    {
        config(['compliance.audit.retention_days' => 30]);

        Carbon::setTestNow(now()->subDays(31));
        app(AuditLogger::class)->record('orders.cancelled');
        Carbon::setTestNow();
        app(AuditLogger::class)->record('orders.refunded');

        $this->artisan('audit:prune')->assertSuccessful();

        $this->assertSame(['orders.refunded'], AuditLog::query()->pluck('action')->all());
        $this->artisan('audit:verify')->assertSuccessful();
    }
}
