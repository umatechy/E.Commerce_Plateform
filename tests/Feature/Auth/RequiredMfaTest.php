<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Compliance\Services\AuditChainVerifier;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Owner decisions of 2026-10-01 on top of Phase B29:
 * - Store Owners must have MFA; store staff stay optional.
 * - A lost authenticator plus lost recovery codes is recovered only by
 *   the server-side emergency reset, never through the application.
 */
final class RequiredMfaTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();

        config(['security.mfa.required_for_store_owners' => true]);
        $this->withHeader('Referer', 'http://localhost'); // a browser session (ADR-002 Surface A)
    }

    private function member(Store $store, string $role): User
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $store->users()->attach($user, ['role_id' => $this->systemRole($store, $role)->id, 'status' => 'active']);

        return $user;
    }

    private function code(User $user, int $stepsAhead = 0): string
    {
        $engine = app(Google2FA::class);

        return $engine->oathTotp((string) $user->refresh()->mfa_secret, $engine->getTimestamp() + $stepsAhead);
    }

    private function signIn(User $user): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD]);
    }

    private function signOut(): void
    {
        $this->postJson('/api/v1/auth/logout');
        $this->app['auth']->forgetGuards();
    }

    public function test_an_owner_without_mfa_can_only_enrol(): void
    {
        $owner = $this->member(Store::factory()->create(), 'owner');
        $this->signIn($owner)->assertOk();

        // The Store Admin surface is closed...
        $this->getJson('/api/v1/team/summary')->assertStatus(403)->assertJsonPath('code', 'mfa_enrollment_required');
        $this->getJson('/api/v1/orders')->assertStatus(403)->assertJsonPath('code', 'mfa_enrollment_required');
        $this->postJson('/api/v1/team/invitations', [])->assertStatus(403)->assertJsonPath('code', 'mfa_enrollment_required');
        // ...and its pages lead to enrollment.
        $this->withoutVite()->get('/')->assertRedirect('/security');
        $this->withoutVite()->get('/orders')->assertRedirect('/security');
        $this->withoutVite()->get('/security')->assertOk();

        // What enrollment needs stays open.
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->getJson('/api/v1/auth/mfa')->assertOk()->assertJsonPath('data.required', true)->assertJsonPath('data.enabled', false);

        $this->postJson('/api/v1/auth/mfa/setup', ['password' => self::PASSWORD])->assertOk();
        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->code($owner)])->assertOk();

        // Enrolled in this session: the surface opens without signing in again.
        $this->getJson('/api/v1/team/summary')->assertOk();
        $this->withoutVite()->get('/orders')->assertOk();
    }

    public function test_an_owner_with_mfa_signs_in_with_two_steps_and_gets_no_remember_me_cookie(): void
    {
        $owner = $this->member(Store::factory()->create(), 'owner');
        $this->signIn($owner);
        $this->postJson('/api/v1/auth/mfa/setup', ['password' => self::PASSWORD]);
        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->code($owner)])->assertOk();
        $this->signOut();

        $this->signIn($owner)->assertStatus(202);
        $this->getJson('/api/v1/team/summary')->assertUnauthorized();

        $response = $this->postJson('/api/v1/auth/login/mfa', ['code' => $this->code($owner, 1)])->assertOk();
        $this->getJson('/api/v1/team/summary')->assertOk();

        // A remember-me cookie would later start a session that skipped the code.
        $this->assertSame([], array_filter($response->headers->getCookies(), fn ($cookie) => str_starts_with($cookie->getName(), 'remember_')));
    }

    public function test_an_owner_session_that_did_not_pass_mfa_is_refused(): void
    {
        $owner = $this->member(Store::factory()->create(), 'owner');
        $this->signIn($owner);
        $this->postJson('/api/v1/auth/mfa/setup', ['password' => self::PASSWORD]);
        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->code($owner)])->assertOk();
        $this->signOut();
        $this->app['session']->flush();

        // A session that began without the second step (for example an old remember-me cookie).
        $this->actingAs($owner->refresh());
        $this->getJson('/api/v1/team/summary')->assertStatus(403)->assertJsonPath('code', 'mfa_verification_required');
        $this->withoutVite()->get('/orders')->assertRedirect('/login');
    }

    public function test_store_staff_are_not_required_to_use_mfa(): void
    {
        $this->seed(\Database\Seeders\PermissionSeeder::class); // the roles' permissions (orders.view)
        $store = Store::factory()->create();

        foreach (['administrator', 'manager', 'staff'] as $role) {
            $member = $this->member($store, $role);
            $this->signIn($member)->assertOk();
            $this->getJson('/api/v1/orders')->assertOk();
            $this->getJson('/api/v1/auth/mfa')->assertJsonPath('data.required', false);
            $this->withoutVite()->get('/orders')->assertOk();
            $this->signOut();
        }
    }

    public function test_a_suspended_ownership_does_not_count(): void
    {
        $store = Store::factory()->create();
        $user = $this->member($store, 'owner');
        DB::table('store_user')->where('user_id', $user->id)->update(['status' => 'suspended']);

        $this->assertFalse($user->ownsAStore());
    }

    public function test_every_staff_route_except_enrollment_is_behind_the_requirement(): void
    {
        $open = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('staff.principal', $route->gatherMiddleware(), true))
            ->reject(fn ($route) => in_array('required.mfa', $route->gatherMiddleware(), true))
            ->map(fn ($route) => $route->uri())->values()->all();

        $this->assertSame([], $open, 'A staff route is missing the required.mfa middleware.');
    }

    // --- Emergency reset (runbook "Emergency MFA reset") ---

    private function enrolled(array $attributes = []): User
    {
        $user = User::factory()->create(['password' => self::PASSWORD, ...$attributes]);
        $this->actingAs($user)->postJson('/api/v1/auth/mfa/setup', ['password' => self::PASSWORD])->assertOk();
        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->code($user)])->assertOk();
        $this->signOut();
        $this->app['session']->flush();

        return $user->refresh();
    }

    public function test_the_emergency_reset_removes_mfa_and_writes_a_high_severity_audit_entry(): void
    {
        $staff = $this->enrolled(['platform_role' => 'support_agent']);
        $secret = (string) $staff->mfa_secret;
        $rememberToken = $staff->remember_token;

        $this->artisan('mfa:emergency-reset', [
            'email' => $staff->email, '--operator' => 'A. Operator', '--reason' => 'Phone lost, codes lost. Incident INC-0042', '--confirm' => $staff->email,
        ])->doesntExpectOutputToContain($secret)->assertSuccessful();

        $staff->refresh();
        $this->assertFalse($staff->hasMfaEnabled());
        $this->assertNull($staff->mfa_secret);
        $this->assertSame(0, DB::table('user_mfa_recovery_codes')->where('user_id', $staff->id)->count());
        $this->assertNotSame($rememberToken, $staff->remember_token);

        $entry = AuditLog::query()->where('action', 'auth.mfa.emergency_reset')->firstOrFail();
        $this->assertSame('high', $entry->contextData()['severity']);
        $this->assertSame('A. Operator', $entry->contextData()['operator']);
        $this->assertSame('Phone lost, codes lost. Incident INC-0042', $entry->contextData()['reason']);
        $this->assertSame('platform_staff', $entry->contextData()['account_type']);
        $this->assertSame($staff->public_id, $entry->subject_public_id);
        $this->assertStringNotContainsString($secret, (string) $entry->getRawOriginal('context'));
        $this->assertSame('ok', app(AuditChainVerifier::class)->verify('platform')['status']);

        // The account must enrol again before it can use the Super Admin surface.
        config(['security.mfa.required_for_platform_staff' => true]);
        $this->signIn($staff)->assertOk(); // one step: there is no MFA any more
        $this->getJson('/api/v1/super-admin/dashboard')->assertStatus(403)->assertJsonPath('code', 'mfa_enrollment_required');
    }

    public function test_the_emergency_reset_refuses_without_operator_reason_and_matching_confirmation(): void
    {
        $staff = $this->enrolled(['platform_role' => 'support_agent']);
        $arguments = ['email' => $staff->email, '--operator' => 'A. Operator', '--reason' => 'Phone lost. Incident INC-0042', '--confirm' => $staff->email];

        $this->artisan('mfa:emergency-reset', [...$arguments, '--operator' => ''])->assertFailed();
        $this->artisan('mfa:emergency-reset', [...$arguments, '--reason' => 'lost'])->assertFailed();
        $this->artisan('mfa:emergency-reset', [...$arguments, '--confirm' => 'someone-else@example.com'])->assertFailed();
        $this->artisan('mfa:emergency-reset', [...$arguments, 'email' => 'nobody@example.com', '--confirm' => 'nobody@example.com'])->assertFailed();
        // Not interactive and no --confirm: nothing to confirm with.
        $this->artisan('mfa:emergency-reset', array_diff_key($arguments, ['--confirm' => true]) + ['--no-interaction' => true])->assertFailed();

        $this->assertTrue($staff->refresh()->hasMfaEnabled());
        $this->assertSame(0, AuditLog::query()->where('action', 'auth.mfa.emergency_reset')->count());

        // An account without MFA has nothing to reset.
        $plain = User::factory()->create();
        $this->artisan('mfa:emergency-reset', [...$arguments, 'email' => $plain->email, '--confirm' => $plain->email])->assertFailed();
    }

    public function test_no_http_route_can_reset_another_accounts_mfa(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'mfa'))
            ->map(fn ($route) => $route->methods()[0].' '.$route->uri())->sort()->values()->all();

        // Only the signed-in user's own MFA and the sign-in step. None takes a user id.
        $this->assertSame([
            'DELETE api/v1/auth/mfa',
            'GET api/v1/auth/mfa',
            'POST api/v1/auth/login/mfa',
            'POST api/v1/auth/mfa/confirm',
            'POST api/v1/auth/mfa/recovery-codes',
            'POST api/v1/auth/mfa/setup',
        ], $routes);
    }
}
