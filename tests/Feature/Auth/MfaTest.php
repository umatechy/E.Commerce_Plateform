<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Phase B29 (gap G2) — multi-factor authentication for staff accounts
 * (Module 32 §8, §65; SRS AUTH-006, AUTH-007, AUTH-012).
 */
final class MfaTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();

        // A browser of the admin app: Sanctum gives it a session (ADR-002 Surface A).
        $this->withHeader('Referer', 'http://localhost');
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(['password' => self::PASSWORD, ...$attributes]);
    }

    /** A valid code; $stepsAhead > 0 gives a later one, since a code works only once. */
    private function code(User $user, int $stepsAhead = 0): string
    {
        $engine = app(Google2FA::class);

        return $engine->oathTotp((string) $user->refresh()->mfa_secret, $engine->getTimestamp() + $stepsAhead);
    }

    /**
     * A valid code "some minutes later". Real time cannot pass in a test
     * and only codes one step either side of now are accepted, so the
     * replay marker is moved back instead, as it would be by then.
     */
    private function laterCode(User $user): string
    {
        $engine = app(Google2FA::class);
        $user->refresh()->forceFill(['mfa_last_used_step' => $engine->getTimestamp() - 10])->save();
        $this->app['auth']->forgetGuards(); // a new request loads the user again, as in production

        return $engine->oathTotp((string) $user->mfa_secret, $engine->getTimestamp());
    }

    /** @return list<string> the recovery codes */
    private function enroll(User $user): array
    {
        $this->actingAs($user)->postJson('/api/v1/auth/mfa/setup', ['password' => self::PASSWORD])->assertOk();
        $codes = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->code($user)])->assertOk()->json('data.recovery_codes');

        // Back to a signed-out browser for the sign-in tests.
        Auth::guard('web')->logout();
        $this->app['session']->flush();
        $this->app['auth']->forgetGuards();

        return $codes;
    }

    private function signIn(User $user): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD]);
    }

    public function test_enrollment_needs_the_password_and_a_code_from_the_app(): void
    {
        $user = $this->user();

        $this->actingAs($user)->postJson('/api/v1/auth/mfa/setup', ['password' => 'wrong'])->assertStatus(422)->assertJsonValidationErrors('password');

        $setup = $this->postJson('/api/v1/auth/mfa/setup', ['password' => self::PASSWORD])->assertOk();
        $this->assertStringStartsWith('otpauth://totp/', $setup->json('data.otpauth_uri'));
        $this->assertFalse($user->refresh()->hasMfaEnabled()); // a secret alone does nothing

        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => '000000'])->assertStatus(422)->assertJsonValidationErrors('code');

        $confirm = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->code($user)])->assertOk()->assertJsonPath('data.enabled', true);
        $this->assertCount(10, $confirm->json('data.recovery_codes'));
        $this->assertTrue($user->refresh()->hasMfaEnabled());

        // The secret is encrypted at rest and never comes back from the API.
        $stored = DB::table('users')->where('id', $user->id)->value('mfa_secret');
        $this->assertNotSame($setup->json('data.secret'), $stored);
        $this->assertStringNotContainsString($setup->json('data.secret'), $this->getJson('/api/v1/auth/me')->getContent());
        $this->getJson('/api/v1/auth/mfa')->assertOk()->assertJsonPath('data.recovery_codes_remaining', 10)->assertJsonMissingPath('data.secret');

        // Recovery codes are stored as hashes only.
        $this->assertSame(0, DB::table('user_mfa_recovery_codes')->whereIn('code_hash', $confirm->json('data.recovery_codes'))->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.mfa.enabled')->count());
    }

    public function test_a_code_typed_with_the_space_the_app_shows_is_accepted(): void
    {
        // Authenticator apps show "123 456". Typed like that, a right code was refused as "not valid".
        $user = $this->user();
        $spaced = fn (string $code) => ' '.substr($code, 0, 3).' '.substr($code, 3).' ';

        $this->actingAs($user)->postJson('/api/v1/auth/mfa/setup', ['password' => self::PASSWORD])->assertOk();
        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $spaced($this->code($user))])->assertOk()->assertJsonPath('data.enabled', true);

        $this->postJson('/api/v1/auth/logout');
        $this->app['auth']->forgetGuards();
        $this->signIn($user)->assertStatus(202);
        $this->postJson('/api/v1/auth/login/mfa', ['code' => $spaced($this->code($user, 1))])->assertOk();
        // A wrong code with a space is still wrong.
        $this->postJson('/api/v1/auth/logout');
        $this->app['auth']->forgetGuards();
        $this->signIn($user)->assertStatus(202);
        $this->postJson('/api/v1/auth/login/mfa', ['code' => '000 000'])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_a_password_alone_does_not_sign_in_an_account_with_mfa(): void
    {
        $user = $this->user();
        $this->enroll($user);

        $this->signIn($user)->assertStatus(202)->assertJsonPath('data.mfa_required', true);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertSame(0, AuditLog::query()->where('action', 'auth.login.succeeded')->count());

        $this->postJson('/api/v1/auth/login/mfa', ['code' => '000000'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.mfa.failed')->count());

        $this->postJson('/api/v1/auth/login/mfa', ['code' => $this->code($user, 1)])->assertOk()->assertJsonPath('data.mfa_enabled', true);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', $user->email);
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.login.succeeded')->count());
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $user = $this->user();
        $this->enroll($user);
        $code = $this->code($user, 1);

        $this->signIn($user)->assertStatus(202);
        $this->postJson('/api/v1/auth/login/mfa', ['code' => $code])->assertOk();

        // Someone who saw that code cannot reuse it, here for a step-up.
        $this->postJson('/api/v1/auth/step-up', ['password' => self::PASSWORD, 'code' => $code])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_a_recovery_code_signs_in_once(): void
    {
        $user = $this->user();
        $recovery = $this->enroll($user)[0];

        $this->signIn($user)->assertStatus(202);
        $this->postJson('/api/v1/auth/login/mfa', ['code' => strtolower($recovery)])->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.mfa.recovery_code_used')->count());
        $this->getJson('/api/v1/auth/mfa')->assertJsonPath('data.recovery_codes_remaining', 9);

        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->signIn($user)->assertStatus(202);
        $this->postJson('/api/v1/auth/login/mfa', ['code' => $recovery])->assertStatus(422);
    }

    public function test_wrong_codes_lock_the_second_step_even_for_a_right_code(): void
    {
        $user = $this->user();
        $this->enroll($user);
        $this->signIn($user)->assertStatus(202);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login/mfa', ['code' => '000000'])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login/mfa', ['code' => $this->code($user, 1)])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_the_second_step_must_follow_a_password_and_times_out(): void
    {
        $user = $this->user();
        $this->enroll($user);

        // No password step at all.
        $this->postJson('/api/v1/auth/login/mfa', ['code' => $this->code($user, 1)])->assertStatus(409)->assertJsonPath('code', 'mfa_challenge_expired');

        $this->signIn($user)->assertStatus(202);
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/auth/login/mfa', ['code' => $this->code($user, 1)])->assertStatus(409);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_turning_mfa_off_needs_the_password_and_a_code(): void
    {
        $user = $this->user();
        $this->enroll($user);
        $this->signIn($user);
        $this->postJson('/api/v1/auth/login/mfa', ['code' => $this->code($user, 1)])->assertOk();

        $this->deleteJson('/api/v1/auth/mfa', ['password' => self::PASSWORD])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->deleteJson('/api/v1/auth/mfa', ['password' => 'wrong', 'code' => $this->laterCode($user)])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->assertTrue($user->refresh()->hasMfaEnabled());

        $this->deleteJson('/api/v1/auth/mfa', ['password' => self::PASSWORD, 'code' => $this->laterCode($user)])->assertOk()->assertJsonPath('data.enabled', false);
        $this->assertFalse($user->refresh()->hasMfaEnabled());
        $this->assertSame(0, DB::table('user_mfa_recovery_codes')->where('user_id', $user->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.mfa.disabled')->count());

        // Sign-in is one step again.
        $this->postJson('/api/v1/auth/logout');
        $this->app['auth']->forgetGuards();
        $this->signIn($user)->assertOk();
    }

    public function test_new_recovery_codes_replace_the_old_ones(): void
    {
        $user = $this->user();
        $old = $this->enroll($user);
        $this->signIn($user);
        $this->postJson('/api/v1/auth/login/mfa', ['code' => $this->code($user, 1)])->assertOk();

        $new = $this->postJson('/api/v1/auth/mfa/recovery-codes', ['password' => self::PASSWORD, 'code' => $this->laterCode($user)])->assertOk()->json('data.recovery_codes');

        $this->assertCount(10, $new);
        $this->assertSame([], array_intersect($old, $new));
        $this->postJson('/api/v1/auth/step-up', ['password' => self::PASSWORD, 'code' => $old[0]])->assertStatus(422);
        $this->postJson('/api/v1/auth/step-up', ['password' => self::PASSWORD, 'code' => $new[0]])->assertOk();
    }

    public function test_platform_staff_reach_the_super_admin_surface_only_through_mfa(): void
    {
        config(['security.mfa.required_for_platform_staff' => true]);
        $staff = $this->user(['platform_role' => 'support_agent']);

        // No MFA on the account.
        $this->actingAs($staff)->getJson('/api/v1/super-admin/dashboard')->assertStatus(403)->assertJsonPath('code', 'mfa_enrollment_required');

        // MFA on the account, but this session never passed it.
        $this->enroll($staff);
        $this->actingAs($staff->refresh())->getJson('/api/v1/super-admin/dashboard')->assertStatus(403)->assertJsonPath('code', 'mfa_verification_required');

        // A real two-step sign-in.
        Auth::guard('web')->logout();
        $this->app['auth']->forgetGuards();
        $this->signIn($staff)->assertStatus(202);
        $this->postJson('/api/v1/auth/login/mfa', ['code' => $this->code($staff, 1)])->assertOk();
        $this->getJson('/api/v1/super-admin/dashboard')->assertOk();
    }

    public function test_store_staff_are_not_forced_to_use_mfa(): void
    {
        config(['security.mfa.required_for_platform_staff' => true]);
        $user = $this->user();

        $this->signIn($user)->assertOk()->assertJsonPath('data.mfa_enabled', false);
        $this->getJson('/api/v1/auth/mfa')->assertOk()->assertJsonPath('data.required', false);
        // ...and the Super Admin surface refuses them as before, not with an MFA prompt.
        $this->getJson('/api/v1/super-admin/dashboard')->assertStatus(403)->assertJsonMissingPath('code');
    }
}
