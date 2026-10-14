<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationMessageType;
use App\Domain\Notifications\Models\RecipientType;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Security\Services\SecurityMonitor;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase B52 (Module 32 §7 "Password Security", §9.5) — staff accounts
 * (store owners, store staff, Umar Techy staff) change and recover their
 * password. Until B52 there was no way to do either.
 *
 * - Changing needs the current password; every other session of the
 *   account ends and remembered sign-ins are revoked (§9.5).
 * - "Forgot password" never says whether an account exists (§7.6). Tokens
 *   are 64 random characters, stored only as SHA-256, valid 60 minutes,
 *   used once; a new request replaces an older one; at most one email a
 *   minute per account.
 * - A reset signs the account out everywhere. It does not touch two-step
 *   sign-in: an account with MFA still needs its code to sign in.
 * - The account holder is told by email whenever the password changes.
 */
final class StaffPasswordService
{
    private const TTL_MINUTES = 60;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
        private readonly SecurityMonitor $monitor,
        private readonly UserSessions $sessions,
    ) {}

    public function change(User $user, string $current, string $new, Request $request): void
    {
        if (! Hash::check($current, (string) $user->password)) {
            $this->monitor->record('step_up_failed', $user, context: ['route' => 'auth/password']);
            throw ValidationException::withMessages(['current_password' => 'This is not your current password.']);
        }
        if (Hash::check($new, (string) $user->password)) {
            throw ValidationException::withMessages(['password' => 'Choose a password different from the current one.']);
        }
        $user->forceFill(['password' => $new])->save(); // the `hashed` cast hashes it
        $ended = $this->sessions->revokeAll($user, 'password_changed', $user, keep: $request);
        $this->audit->record('auth.password_changed', ['other_sessions_ended' => $ended], $user, actor: $user, platform: true);
        $this->monitor->record('password_changed', $user, context: ['other_sessions_ended' => $ended]);
        $this->tell($user, 'changed');
    }

    public function requestReset(string $email, ?string $ip): void
    {
        $user = User::query()->where('email', $email)->where('is_active', true)->first();
        $this->monitor->record('password_reset_requested', $user, SecurityMonitor::maskEmail($email), ip: $ip);
        if ($user === null || DB::table('staff_password_resets')->where('user_id', $user->id)->where('created_at', '>', now()->subMinute())->exists()) {
            return;
        }
        $token = Str::random(64);
        $resetId = DB::transaction(function () use ($user, $token, $ip) {
            DB::table('staff_password_resets')->where('user_id', $user->id)->whereNull('used_at')->delete();

            return DB::table('staff_password_resets')->insertGetId([
                'user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(self::TTL_MINUTES),
                'requested_ip' => $ip, 'created_at' => now(),
            ]);
        });
        // In the #fragment: the token never reaches a server log (as invitation links, Phase G1).
        $link = rtrim((string) config('app.url'), '/').'/reset-password#'.http_build_query(['token' => $token, 'email' => $user->email]);
        app(TenantContext::class)->asPlatform(fn () => $this->notifications->send(
            NotificationMessageType::Security, NotificationChannel::Email, RecipientType::User, $user->id, $user->email,
            'Reset your Umar Techy password',
            'Hi {{user.name}}, we received a request to reset the password of your Umar Techy account. Use this link within 60 minutes to choose a new one: {{reset.link}} If you did not ask for this, ignore this email; your password stays the same.',
            ['user.name' => $user->name], "staff-password-reset:{$resetId}", 'auth.password_reset_requested',
            secretVariables: ['reset.link' => $link], // the token never sits readable in notification_messages
        ));
        $this->audit->record('auth.password_reset_requested', [], $user, platform: true);
    }

    /** @return bool false when the token is unknown, used, expired or for another account */
    public function reset(string $email, string $token, string $password): bool
    {
        $user = DB::transaction(function () use ($email, $token, $password) {
            $row = DB::table('staff_password_resets')->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            $user = $row !== null ? User::query()->find($row->user_id) : null;
            if ($row === null || $user === null || $row->used_at !== null || now()->greaterThan($row->expires_at)
                || ! hash_equals(mb_strtolower($user->email), mb_strtolower($email)) || ! $user->is_active) {
                return null;
            }
            $user->forceFill(['password' => $password])->save();
            DB::table('staff_password_resets')->where('id', $row->id)->update(['used_at' => now()]);
            DB::table('staff_password_resets')->where('user_id', $user->id)->whereNull('used_at')->delete();

            return $user;
        });
        if ($user === null) {
            return false;
        }
        $ended = $this->sessions->revokeAll($user, 'password_changed');
        $this->audit->record('auth.password_reset', ['sessions_ended' => $ended], $user, actor: $user, platform: true);
        $this->monitor->record('password_reset', $user, context: ['sessions_ended' => $ended]);
        $this->tell($user, 'reset');

        return true;
    }

    private function tell(User $user, string $how): void
    {
        app(TenantContext::class)->asPlatform(fn () => $this->notifications->send(
            NotificationMessageType::Security, NotificationChannel::Email, RecipientType::User, $user->id, $user->email,
            'Your Umar Techy password was changed',
            'Hi {{user.name}}, the password of your Umar Techy account was '.($how === 'reset' ? 'reset with an emailed link' : 'changed').' on {{changed.at}} UTC. You were signed out on your other devices. If this was not you, reset your password now and contact Umar Techy support.',
            ['user.name' => $user->name, 'changed.at' => now('UTC')->format('Y-m-d H:i')],
            "staff-password-changed:{$user->id}:".now()->getTimestamp().':'.Str::random(6), 'auth.password_changed',
        ));
    }
}
