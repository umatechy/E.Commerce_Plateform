<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\MfaRecoveryCode;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Multi-factor authentication for staff accounts (Module 32 §8,
 * Module 02 §13; SRS AUTH-006, AUTH-007): time-based one-time codes
 * (TOTP, RFC 6238) from an authenticator app, plus single-use recovery
 * codes. The one place MFA state is read or changed.
 *
 * - The secret is encrypted at rest and never returned after enrollment.
 * - A code is accepted once: the step of the last accepted code is kept.
 * - Recovery codes are shown once; only a keyed hash is stored.
 * - Wrong codes are counted per account, across TOTP and recovery codes.
 * - Enrollment, removal, recovery-code use and failures are audited (§8.5).
 */
final class MfaService
{
    /** One 30-second step either side of now, for clock drift. */
    private const WINDOW = 1;

    public function __construct(
        private readonly Google2FA $engine,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Starts (or restarts) enrollment: a new secret that does nothing
     * until confirmEnrollment() proves the authenticator app has it.
     *
     * @return array{secret: string, otpauth_uri: string}
     */
    public function beginEnrollment(User $user): array
    {
        if ($user->hasMfaEnabled()) {
            throw new \LogicException('MFA is already enabled for this account.');
        }

        $secret = $this->engine->generateSecretKey(32);
        $user->forceFill(['mfa_secret' => $secret, 'mfa_confirmed_at' => null, 'mfa_last_used_step' => null])->save();

        return [
            'secret' => $secret,
            'otpauth_uri' => $this->engine->getQRCodeUrl((string) config('security.mfa.issuer'), $user->email, $secret),
        ];
    }

    /**
     * @return list<string> the recovery codes, shown this once
     * @throws InvalidMfaCodeException
     */
    public function confirmEnrollment(User $user, string $code): array
    {
        if ($user->hasMfaEnabled() || $user->mfa_secret === null) {
            throw new \LogicException('There is no MFA enrollment to confirm.');
        }

        $this->guardAttempts($user);

        if (! $this->acceptTotp($user, $code)) {
            $this->failed($user, 'enrollment');
        }

        $codes = DB::transaction(function () use ($user) {
            $user->forceFill(['mfa_confirmed_at' => now()])->save();

            return $this->replaceRecoveryCodes($user);
        });

        RateLimiter::clear($this->attemptKey($user));
        $this->audit->record('auth.mfa.enabled', [], $user, actor: $user, platform: true);

        return $codes;
    }

    /**
     * A sign-in or step-up check: a 6-digit app code, or a recovery code.
     *
     * @param string $purpose  where the code was asked for, for the audit trail
     * @throws InvalidMfaCodeException
     */
    public function verify(User $user, string $code, string $purpose): void
    {
        if (! $user->hasMfaEnabled()) {
            throw new \LogicException('MFA is not enabled for this account.');
        }

        $this->guardAttempts($user);

        $accepted = preg_match('/^\d{6}$/', trim($code)) === 1
            ? $this->acceptTotp($user, $code)
            : $this->consumeRecoveryCode($user, $code);

        if (! $accepted) {
            $this->failed($user, $purpose);
        }

        RateLimiter::clear($this->attemptKey($user));
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->replaceRecoveryCodes($user);
        $this->audit->record('auth.mfa.recovery_codes_regenerated', [], $user, actor: $user, platform: true);

        return $codes;
    }

    /** Removes MFA. The caller has already verified the password and a code (§8.6). */
    public function disable(User $user): void
    {
        DB::transaction(function () use ($user) {
            MfaRecoveryCode::query()->where('user_id', $user->id)->delete();
            $user->forceFill(['mfa_secret' => null, 'mfa_confirmed_at' => null, 'mfa_last_used_step' => null])->save();
        });

        $this->audit->record('auth.mfa.disabled', [], $user, actor: $user, platform: true);
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return MfaRecoveryCode::query()->where('user_id', $user->id)->whereNull('used_at')->count();
    }

    private function acceptTotp(User $user, string $code): bool
    {
        // Returns the step of the code when it is valid AND newer than the
        // last accepted one. (0, not null: with null the library returns
        // true instead of the step, and the code could be replayed.)
        $step = $this->engine->verifyKeyNewer((string) $user->mfa_secret, trim($code), $user->mfa_last_used_step ?? 0, self::WINDOW);

        if (! is_int($step)) {
            return false;
        }

        $user->forceFill(['mfa_last_used_step' => $step])->save();

        return true;
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        // The UPDATE is the check: of two concurrent uses of one code, one changes a row.
        $used = MfaRecoveryCode::query()
            ->where('user_id', $user->id)
            ->where('code_hash', self::hash($code))
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        if ($used !== 1) {
            return false;
        }

        $this->audit->record('auth.mfa.recovery_code_used', ['remaining' => $this->remainingRecoveryCodes($user)], $user, actor: $user, platform: true);

        return true;
    }

    /** @return list<string> */
    private function replaceRecoveryCodes(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < (int) config('security.mfa.recovery_codes'); $i++) {
            // 10 random letters and digits: about 51 bits per code.
            $codes[] = Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5));
        }

        DB::transaction(function () use ($user, $codes) {
            MfaRecoveryCode::query()->where('user_id', $user->id)->delete();
            foreach ($codes as $code) {
                MfaRecoveryCode::query()->create(['user_id' => $user->id, 'code_hash' => self::hash($code)]);
            }
        });

        return $codes;
    }

    /** @throws InvalidMfaCodeException */
    private function guardAttempts(User $user): void
    {
        if (RateLimiter::tooManyAttempts($this->attemptKey($user), (int) config('security.mfa.max_attempts'))) {
            throw InvalidMfaCodeException::tooManyAttempts((int) ceil(RateLimiter::availableIn($this->attemptKey($user)) / 60));
        }
    }

    /** @throws InvalidMfaCodeException */
    private function failed(User $user, string $purpose): never
    {
        RateLimiter::hit($this->attemptKey($user), (int) config('security.mfa.lockout_minutes') * 60);
        $this->audit->record('auth.mfa.failed', ['purpose' => $purpose], $user, actor: $user, platform: true);

        throw InvalidMfaCodeException::wrong();
    }

    private function attemptKey(User $user): string
    {
        return "mfa-attempts:{$user->id}";
    }

    private static function hash(string $code): string
    {
        $normalized = Str::upper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));

        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }
}
