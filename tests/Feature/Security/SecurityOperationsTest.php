<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domain\DeveloperPlatform\Models\ApplicationStatus;
use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use App\Domain\DeveloperPlatform\Services\ApiKeyService;
use App\Domain\Events\Jobs\ConsumeOutboxEventJob;
use App\Domain\Events\Models\OutboxEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserSession;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Security\Models\SecurityEvent;
use App\Domain\SuperAdmin\Support\PlatformRoles;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase B52 — security and operations: platform roles (Module 30 §3),
 * sessions (SRS AUTH-005; Module 32 §9, §65.4), staff passwords (§7),
 * security monitoring and alerts (§58–59), emergency controls (Module 30
 * §60–61) and incidents (Module 30 §59, §87–88; Module 32 §60–64).
 */
final class SecurityOperationsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function staff(string $role, array $attributes = []): User
    {
        return User::factory()->create(['platform_role' => $role, 'password' => self::PASSWORD, ...$attributes]);
    }

    /** Another person in the same test: a fresh browser, so no session is shared between accounts. */
    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web'); // a previous request may have switched the default guard to sanctum

        return $this->actingAs($user);
    }

    /**
     * One request as a browser makes it: the session cookie the server set
     * last time goes back with it, so the server sees one continuing session.
     */
    private function browser(string $method, string $uri, array $data = []): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards(); // each request loads the user from its session, as in production
        // A browser of the admin app (Referer: Sanctum gives it a session); JSON requests send cookies only "with credentials".
        $response = $this->withHeader('Referer', 'http://localhost')->withCredentials()->json($method, $uri, $data);
        $cookie = $response->getCookie((string) config('session.cookie'));
        if ($cookie !== null) {
            $this->withCookie((string) config('session.cookie'), (string) $cookie->getValue());
        }

        return $response;
    }

    private function deliverEvents(): void
    {
        OutboxEvent::query()->withoutTenantScope()->where('status', 'pending')->orderBy('id')->pluck('id')
            ->each(fn (int $id) => app()->call([new ConsumeOutboxEventJob($id), 'handle']));
    }

    public function test_every_super_admin_route_needs_a_capability_and_roles_get_only_theirs(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/super-admin')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->assertNotNull(PlatformRoles::capabilityFor($method, $route->uri()), "{$method} {$route->uri()} has no platform capability");
            }
        }

        $auditor = $this->staff('read_only_auditor');
        $this->as($auditor)->getJson('/api/v1/super-admin/packages')->assertOk();
        $this->as($auditor)->postJson('/api/v1/super-admin/packages', ['code' => 'x', 'name' => 'X'])->assertForbidden()->assertJsonPath('code', 'platform_role_forbidden');

        $billing = $this->staff('billing_admin');
        $this->as($billing)->putJson('/api/v1/super-admin/settings/platform.trial_days', ['value' => 30])->assertForbidden()->assertJsonPath('capability', 'settings');
        $support = $this->staff('support_agent'); // a role from before B52 is read as support admin
        $store = Store::factory()->create();
        $this->as($support)->postJson("/api/v1/super-admin/stores/{$store->id}/lifecycle/suspend", ['reason' => 'abuse', 'note' => 'Should not be allowed'])->assertForbidden();
        $this->assertSame(PlatformRoles::capabilitiesOf('support_admin'), PlatformRoles::capabilitiesOf('support_agent'));
        $this->assertSame([], PlatformRoles::capabilitiesOf(null));
        $this->assertSame(['view'], PlatformRoles::capabilitiesOf('made_up_role'), 'unknown roles fail closed');
    }

    public function test_roles_change_only_by_security_staff_never_their_own_and_never_the_last_super_admin(): void
    {
        $boss = $this->staff('super_admin');
        $security = $this->staff('security_admin');
        $ops = $this->staff('operations_admin');
        $customerUser = User::factory()->create();

        $this->as($ops)->putJson("/api/v1/super-admin/users/{$security->public_id}/platform-role", ['role' => 'read_only_auditor', 'reason' => 'Rotation'])->assertForbidden();
        $this->as($security)->putJson("/api/v1/super-admin/users/{$security->public_id}/platform-role", ['role' => 'super_admin', 'reason' => 'Promote myself'])->assertStatus(422);
        $this->as($security)->putJson("/api/v1/super-admin/users/{$customerUser->public_id}/platform-role", ['role' => 'support_admin', 'reason' => 'New hire'])->assertStatus(422);
        $this->as($security)->putJson("/api/v1/super-admin/users/{$boss->public_id}/platform-role", ['role' => 'read_only_auditor', 'reason' => 'Demote the only one'])->assertStatus(422)->assertJsonValidationErrors('role');

        $this->as($security)->putJson("/api/v1/super-admin/users/{$ops->public_id}/platform-role", ['role' => 'support_admin', 'reason' => 'Moved to support'])->assertOk();
        $this->assertSame('support_admin', $ops->fresh()->platform_role);
        $this->assertDatabaseHas('security_events', ['type' => 'alert', 'user_id' => $ops->id, 'severity' => 'high']);
        $this->deliverEvents();
        $this->assertTrue(NotificationMessage::query()->withoutGlobalScopes()->where('subject', 'like', '%Platform staff role changed%')->exists());

        // Platform access itself is given on the server only.
        $this->artisan('platform:staff', ['email' => $customerUser->email, 'role' => 'billing_admin', '--operator' => 'Umar', '--reason' => 'Finance team hire ticket 12', '--confirm' => $customerUser->email])->assertSuccessful();
        $this->assertSame('billing_admin', $customerUser->fresh()->platform_role);
        $this->artisan('platform:staff', ['email' => $customerUser->email, 'role' => 'super_admin', '--operator' => 'Umar', '--reason' => 'Finance team hire ticket 12', '--confirm' => 'wrong@example.com'])->assertFailed();
    }

    public function test_sessions_are_listed_ended_from_another_device_and_by_a_password_change(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->browser('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
        $this->browser('GET', '/api/v1/auth/me')->assertOk();
        $phone = UserSession::query()->create(['user_id' => $user->id, 'session_hash' => hash('sha256', 'phone'), 'user_agent' => 'Mozilla/5.0 (iPhone) Safari/604.1', 'ip_address' => '203.0.113.9', 'last_seen_at' => now()]);

        $list = $this->browser('GET', '/api/v1/auth/sessions')->assertOk();
        $this->assertCount(2, $list->json('data'));
        $this->assertSame(1, collect($list->json('data'))->where('current', true)->count());
        $this->assertContains('Safari on iOS', collect($list->json('data'))->pluck('device')->all());
        $this->assertStringNotContainsString('session_hash', $list->getContent());

        $this->browser('DELETE', "/api/v1/auth/sessions/{$phone->public_id}")->assertOk();
        $this->assertSame('revoked', $phone->fresh()->revoked_reason);

        // The current session itself, ended from elsewhere, signs out on its next request.
        UserSession::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoked_reason' => 'revoked']);
        $this->browser('GET', '/api/v1/auth/me')->assertStatus(401)->assertJsonPath('code', 'session_ended');

        // Changing the password ends the other sessions, keeps this one, and tells the person.
        $this->browser('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
        $this->browser('GET', '/api/v1/auth/me')->assertOk();
        $other = UserSession::query()->create(['user_id' => $user->id, 'session_hash' => hash('sha256', 'laptop'), 'last_seen_at' => now()]);
        $this->browser('PUT', '/api/v1/auth/password', ['current_password' => 'wrong', 'password' => 'a-new-password-1', 'password_confirmation' => 'a-new-password-1'])->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->browser('PUT', '/api/v1/auth/password', ['current_password' => self::PASSWORD, 'password' => 'a-new-password-1', 'password_confirmation' => 'a-new-password-1'])->assertOk();
        $this->assertSame('password_changed', $other->fresh()->revoked_reason);
        $this->browser('GET', '/api/v1/auth/me')->assertOk();
        $this->assertTrue(NotificationMessage::query()->withoutGlobalScopes()->where('destination', $user->email)->where('subject', 'Your Umar Techy password was changed')->exists());
    }

    public function test_platform_staff_sessions_end_after_the_idle_time_and_deactivation_signs_out_everywhere(): void
    {
        $staff = $this->staff('support_admin');
        $this->browser('POST', '/api/v1/auth/login', ['email' => $staff->email, 'password' => self::PASSWORD])->assertOk();
        $this->browser('GET', '/api/v1/auth/me')->assertOk();
        $this->assertTrue(UserSession::query()->where('user_id', $staff->id)->sole()->privileged);

        $this->travel((int) config('security.sessions.privileged_idle_minutes') + 1)->minutes();
        $this->browser('GET', '/api/v1/auth/me')->assertStatus(401)->assertJsonPath('reason', 'idle_timeout');
        $this->travelBack();

        $boss = $this->staff('super_admin');
        $open = UserSession::query()->create(['user_id' => $staff->id, 'session_hash' => hash('sha256', 'x'), 'last_seen_at' => now()]);
        $this->as($boss)->postJson("/api/v1/super-admin/users/{$staff->id}/deactivate", ['reason' => 'Left the company'])->assertNoContent();
        $this->assertSame('account_deactivated', $open->fresh()->revoked_reason);
        $this->as($boss)->postJson("/api/v1/super-admin/users/{$boss->id}/deactivate")->assertStatus(422);
    }

    public function test_forgot_password_never_reveals_accounts_and_a_reset_works_once_and_ends_sessions(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.com'])->assertStatus(202)->assertJsonPath('message', 'If an account exists for this email, a reset link is on its way.');

        Str::createRandomStringsUsing(fn (int $length) => str_repeat('t', $length));
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertStatus(202);
        Str::createRandomStringsNormally();
        $mail = NotificationMessage::query()->withoutGlobalScopes()->where('destination', $user->email)->where('source_event_type', 'auth.password_reset_requested')->sole();
        $this->assertStringNotContainsString(str_repeat('t', 64), (string) $mail->body, 'the token is never stored readable');
        $this->assertNull($mail->store_id);

        $open = UserSession::query()->create(['user_id' => $user->id, 'session_hash' => hash('sha256', 'old'), 'last_seen_at' => now()]);
        $this->postJson('/api/v1/auth/password/reset', ['email' => $user->email, 'token' => str_repeat('t', 64), 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])->assertOk();
        $this->postJson('/api/v1/auth/password/reset', ['email' => $user->email, 'token' => str_repeat('t', 64), 'password' => 'other-new-pass', 'password_confirmation' => 'other-new-pass'])->assertStatus(422)->assertJsonPath('code', 'invalid_token');
        $this->assertSame('password_changed', $open->fresh()->revoked_reason);
        $this->browser('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => 'brand-new-pass'])->assertOk();
    }

    public function test_repeated_failed_sign_ins_on_a_staff_account_raise_one_alert_to_platform_staff(): void
    {
        $staff = $this->staff('platform_admin');
        $this->staff('security_admin', ['email' => 'security@umartechy.test']);
        foreach (range(1, 6) as $i) {
            $this->app['auth']->forgetGuards();
            app(\Illuminate\Cache\RateLimiter::class)->clear(strtolower($staff->email).'|127.0.0.1'); // the per-account slow-down is not what is tested here
            $this->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'guess-'.$i])->assertStatus(422);
        }

        $this->assertSame(6, SecurityEvent::query()->where('type', 'login_failed')->where('user_id', $staff->id)->count());
        $this->assertSame(1, SecurityEvent::query()->where('type', 'alert')->where('subject', "privileged_login_failures:user:{$staff->id}")->count(), 'one alert per hour, not one per try');
        $this->deliverEvents();
        $alert = NotificationMessage::query()->withoutGlobalScopes()->where('destination', 'security@umartechy.test')->where('subject', 'like', '[CRITICAL] Security:%')->sole();
        $this->assertStringContainsString('failed sign-ins', $alert->body);

        $overview = $this->as($staff)->getJson('/api/v1/super-admin/security')->assertOk();
        $this->assertSame(6, $overview->json('data.failed_privileged_logins_24h'));
        $this->as($staff)->getJson('/api/v1/super-admin/security/events?type=login_failed')->assertOk()->assertJsonPath('meta.total', 6);
    }

    public function test_emergency_controls_need_security_rights_a_reason_and_take_effect_at_once(): void
    {
        $admin = $this->staff('platform_admin');
        $security = $this->staff('security_admin');
        $store = Store::factory()->create(['status' => 'active']);
        app(TenantContext::class)->resolveToStore($store->id);
        $application = DeveloperApplication::factory()->for($store)->create(['status' => ApplicationStatus::Active]);
        $token = app(ApiKeyService::class)->issue($application, ['products:read'])['plaintext'];

        $this->as($admin)->putJson('/api/v1/super-admin/settings/platform.developer_api_enabled', ['value' => false])->assertForbidden()->assertJsonPath('code', 'managed_on_security_page');
        $this->as($admin)->putJson('/api/v1/super-admin/security/emergency/platform.developer_api_enabled', ['value' => false, 'reason' => 'Key leak'])->assertForbidden();
        $this->as($security)->putJson('/api/v1/super-admin/security/emergency/platform.developer_api_enabled', ['value' => false])->assertStatus(422);
        $this->as($security)->putJson('/api/v1/super-admin/security/emergency/platform.developer_api_enabled', ['value' => false, 'reason' => 'INC-2026-0001 key leak'])->assertOk()
            ->assertJsonPath('data.2.emergency', true);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/dev/v1/products', ['Authorization' => "Bearer {$token}"])->assertStatus(503)->assertJsonPath('code', 'developer_api_disabled');
        $this->assertDatabaseHas('security_events', ['type' => 'alert', 'subject' => 'emergency_control:control:'.SecurityEvent::query()->where('type', 'emergency_control_changed')->value('id')]);
        $this->as($security)->putJson('/api/v1/super-admin/security/emergency/not.a.control', ['value' => true, 'reason' => 'Testing'])->assertNotFound();
    }

    public function test_incidents_keep_a_timeline_and_close_only_when_resolved_with_a_resolution(): void
    {
        $security = $this->staff('security_admin');
        $created = $this->as($security)->postJson('/api/v1/super-admin/security/incidents', [
            'title' => 'Many failed sign-ins on staff accounts', 'severity' => 'sev2', 'component' => 'Sign-in', 'scope' => 'platform', 'note' => 'Alert SEC-12 at 10:02',
        ])->assertCreated();
        $id = $created->json('data.id');
        $this->assertMatchesRegularExpression('/^INC-\d{4}-0001$/', $created->json('data.reference'));

        $this->as($security)->patchJson("/api/v1/super-admin/security/incidents/{$id}", ['status' => 'containing', 'note' => 'Blocked the address'])->assertOk();
        $this->as($security)->patchJson("/api/v1/super-admin/security/incidents/{$id}", ['status' => 'resolved'])->assertOk();
        $this->as($security)->patchJson("/api/v1/super-admin/security/incidents/{$id}", ['status' => 'closed'])->assertStatus(422);
        $this->as($security)->patchJson("/api/v1/super-admin/security/incidents/{$id}", ['resolution' => 'Password guessing from one address; blocked; no account entered.'])->assertOk();
        $done = $this->as($security)->patchJson("/api/v1/super-admin/security/incidents/{$id}", ['status' => 'closed'])->assertOk();
        $this->assertSame(['opened', 'status', 'status', 'status'], collect($done->json('data.timeline'))->pluck('kind')->all());
        $this->as($security)->patchJson("/api/v1/super-admin/security/incidents/{$id}", ['note' => 'Late note'])->assertStatus(422);

        $this->as($this->staff('read_only_auditor'))->getJson("/api/v1/super-admin/security/incidents/{$id}")->assertOk();
        $this->as($this->staff('read_only_auditor'))->postJson('/api/v1/super-admin/security/incidents', ['title' => 'Nope nope', 'severity' => 'sev4', 'component' => 'x', 'scope' => 'platform'])->assertForbidden();
    }
}
