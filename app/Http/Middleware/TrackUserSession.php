<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\UserSessions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase B52 (SRS AUTH-005; Module 32 §9.4; Module 30 §5) — records each
 * signed-in staff session and signs out one that was ended: by its owner
 * from another device, by a password change, by Umar Techy, or by the
 * shorter limits for platform staff. Runs on the web pages and on the
 * staff API (same session).
 */
final class TrackUserSession
{
    private const MESSAGES = [
        'idle_timeout' => 'You were signed out after a period without activity. Sign in again.',
        'expired' => 'Your sign-in has reached its time limit. Sign in again.',
        'password_changed' => 'Your password was changed. Sign in again.',
    ];

    public function __construct(private readonly UserSessions $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->hasSession() ? Auth::guard('web')->user() : null;
        if (! $user instanceof User) {
            return $next($request);
        }
        [$row, $ended] = $this->sessions->touch($request, $user);
        if ($row === null && $ended !== null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $message = self::MESSAGES[$ended] ?? 'This sign-in was ended. Sign in again.';

            return $request->expectsJson() || $request->is('api/*')
                ? response()->json(['message' => $message, 'code' => 'session_ended', 'reason' => $ended], 401)
                : redirect()->guest('/login?ended='.rawurlencode($ended));
        }

        return $next($request);
    }
}
