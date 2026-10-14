<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserSession;
use App\Domain\Security\Services\SecurityMonitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase B52 (SRS AUTH-005; Module 32 §9, §65.4; Module 30 §4–5; b29
 * findings 5 and 10) — the signed-in sessions of staff accounts.
 *
 * - Each signed-in browser session gets a row the first time it is seen
 *   (TrackUserSession), keyed by a hash of its session id.
 * - A person sees their sessions and ends any of them; ending a session
 *   takes effect on that session's next request.
 * - Changing the password, an emergency MFA reset and deactivating an
 *   account end every other (or every) session of the account.
 * - Platform staff sessions end after security.sessions.privileged_idle_minutes
 *   without use and after security.sessions.privileged_absolute_hours in all
 *   (Module 30 §5: shorter idle timeout, absolute lifetime).
 */
final class UserSessions
{
    public function __construct(private readonly AuditLogger $audit, private readonly SecurityMonitor $monitor) {}

    public static function hashOf(Request $request): ?string
    {
        return $request->hasSession() ? hash('sha256', (string) $request->session()->getId()) : null;
    }

    /**
     * The current session's row, created on first sight; null when the
     * session was ended (the caller signs it out).
     *
     * @return array{0: ?UserSession, 1: ?string} the row, or null with why it ended
     */
    public function touch(Request $request, User $user): array
    {
        $hash = self::hashOf($request);
        if ($hash === null) {
            return [null, null];
        }
        $row = UserSession::query()->where('session_hash', $hash)->first();
        if ($row === null) {
            $row = UserSession::query()->create([
                'user_id' => $user->id, 'session_hash' => $hash, 'privileged' => $user->isPlatformStaff(),
                'with_mfa' => \App\Domain\Identity\Support\SecuritySession::isMfaVerified($request),
                'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 300), 'last_seen_at' => now(),
            ]);

            return [$row, null];
        }
        if ($row->user_id !== $user->id) {
            return [null, 'revoked']; // never carry a session over to another account
        }
        if ($row->revoked_at !== null) {
            return [null, (string) $row->revoked_reason];
        }
        if ($row->privileged) {
            $idle = (int) config('security.sessions.privileged_idle_minutes');
            $absolute = (int) config('security.sessions.privileged_absolute_hours');
            $reason = match (true) {
                $idle > 0 && $row->last_seen_at->lt(now()->subMinutes($idle)) => 'idle_timeout',
                $absolute > 0 && $row->created_at->lt(now()->subHours($absolute)) => 'expired',
                default => null,
            };
            if ($reason !== null) {
                $row->forceFill(['revoked_at' => now(), 'revoked_reason' => $reason])->save();
                $this->monitor->record('session_timed_out', $user, context: ['reason' => $reason]);

                return [null, $reason];
            }
        }
        if ($row->last_seen_at->lt(now()->subMinute())) {
            $row->forceFill(['last_seen_at' => now(), 'ip_address' => $request->ip()])->save();
        }

        return [$row, null];
    }

    /** The current session is ending (sign-out). */
    public function endCurrent(Request $request, string $reason = 'signed_out'): void
    {
        $hash = self::hashOf($request);
        if ($hash !== null) {
            UserSession::query()->where('session_hash', $hash)->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
        }
    }

    /**
     * End sessions of an account: all of them, or all but the current one.
     *
     * @return int how many were ended
     */
    public function revokeAll(User $user, string $reason, ?User $by = null, ?Request $keep = null): int
    {
        $query = UserSession::query()->where('user_id', $user->id)->whereNull('revoked_at');
        if ($keep !== null && ($hash = self::hashOf($keep)) !== null) {
            $query->where('session_hash', '!=', $hash);
        }
        $count = $query->update(['revoked_at' => now(), 'revoked_reason' => $reason, 'revoked_by' => $by?->id]);
        // Remembered sign-ins would start a new session without a password.
        DB::table('users')->where('id', $user->id)->update(['remember_token' => \Illuminate\Support\Str::random(60)]);
        if ($count > 0) {
            $this->audit->record('auth.sessions_revoked', ['count' => $count, 'reason' => $reason, 'target' => $user->public_id], $user, actor: $by ?? $user, platform: true);
            $this->monitor->record('sessions_revoked', $user, context: ['count' => $count, 'reason' => $reason, 'by' => $by?->email]);
        }

        return $count;
    }

    public function revokeOne(User $user, UserSession $session, ?User $by = null): void
    {
        abort_unless($session->user_id === $user->id, 404);
        if ($session->revoked_at === null) {
            $session->forceFill(['revoked_at' => now(), 'revoked_reason' => 'revoked', 'revoked_by' => ($by ?? $user)->id])->save();
            $this->audit->record('auth.session_revoked', ['session' => $session->public_id], $user, actor: $by ?? $user, platform: true);
        }
    }

    /** @return list<array<string, mixed>> the account's open sessions, newest use first */
    public function listFor(User $user, ?Request $current = null): array
    {
        $hash = $current !== null ? self::hashOf($current) : null;

        return UserSession::query()->where('user_id', $user->id)->whereNull('revoked_at')->orderByDesc('last_seen_at')->limit(50)->get()
            ->map(fn (UserSession $s) => [
                'id' => $s->public_id, 'current' => $hash !== null && hash_equals($s->session_hash, $hash),
                'device' => self::device((string) $s->user_agent), 'ip_address' => $s->ip_address, 'with_mfa' => $s->with_mfa,
                'privileged' => $s->privileged, 'signed_in_at' => $s->created_at->toIso8601String(), 'last_seen_at' => $s->last_seen_at->toIso8601String(),
            ])->all();
    }

    /** "Chrome on Windows" from a user agent; never more detail than people need to recognise a device. */
    public static function device(string $agent): string
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/') => 'Safari',
            $agent === '' => 'Unknown browser',
            default => 'Other browser',
        };
        $system = match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $system === null ? $browser : "{$browser} on {$system}";
    }
}
