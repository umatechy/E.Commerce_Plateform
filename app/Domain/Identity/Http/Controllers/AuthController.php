<?php

declare(strict_types=1);

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Identity\Http\Requests\LoginRequest;
use App\Domain\Identity\Http\Requests\RegisterRequest;
use App\Domain\Identity\Http\Resources\UserResource;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\InvalidMfaCodeException;
use App\Domain\Identity\Services\MfaService;
use App\Domain\Identity\Support\SecuritySession;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tenancy\Services\StoreProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-002 Surface A (Sanctum) authentication endpoints. Session-cookie
 * based for the Inertia SPA — no token is issued or returned here; the
 * browser's session cookie is the credential (standard Sanctum SPA mode).
 */
final class AuthController
{
    public function register(RegisterRequest $request, StoreProvisioningService $provisioning, ConfigService $config): JsonResponse
    {
        // Phase B44 (owner decision 13): Umar Techy may close public sign-up
        // and create stores for customers itself (Super Admin).
        if ($config->get('platform.self_signup_enabled') === false) {
            return response()->json(['message' => 'New stores are set up by the Umar Techy team. Contact us and we will create your store.', 'code' => 'signup_closed'], 403);
        }

        // User + store + owner membership + trial subscription as ONE atomic
        // unit (Module 04 §18: every store has a current package from its
        // first moment). The store is made by StoreProvisioningService, the
        // one creation path for both creation models (Module 03 §5, §55).
        $user = DB::transaction(function () use ($request, $provisioning) {
            $user = User::query()->create([
                'name' => $request->string('name')->toString(),
                'email' => $request->string('email')->toString(),
                'password' => $request->string('password')->toString(), // hashed via cast
            ]);

            $provisioning->selfService($user, $request->string('store_name')->toString(), $request->input('business_category'));

            return $user;
        });

        Auth::guard('web')->login($user);

        // Session-fixation protection applies to the stateful SPA flow
        // (ADR-002 Surface A). A non-stateful request carries no session
        // at all (SecuritySession checks), and calling ->session() on it
        // threw a 500.
        SecuritySession::signedIn($request, withMfa: false);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $owesSecondFactor = $request->authenticate();

        // Module 32 §8: the password was right, but this account has MFA.
        // Nobody is signed in yet; the session only remembers who must
        // still enter a code (POST /auth/login/mfa).
        if ($owesSecondFactor !== null) {
            if (! $request->hasSession()) {
                throw ValidationException::withMessages(['email' => 'This account uses two-step sign-in, which needs a browser session.']);
            }

            SecuritySession::beginMfaChallenge($request, $owesSecondFactor);

            return response()->json(['data' => ['mfa_required' => true]], 202);
        }

        SecuritySession::signedIn($request, withMfa: false);

        return (new UserResource(Auth::guard('web')->user()))->response();
    }

    /** The second step of a sign-in: an authenticator or recovery code. */
    public function loginMfa(Request $request, MfaService $mfa): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:32']]);

        $userId = SecuritySession::pendingUserId($request);
        $user = $userId !== null ? User::query()->find($userId) : null;

        // No open challenge, or it timed out: start again from the password.
        if ($user === null || ! $user->is_active || ! $user->hasMfaEnabled()) {
            return response()->json(['message' => 'Sign in again.', 'code' => 'mfa_challenge_expired'], 409);
        }

        try {
            $mfa->verify($user, $request->string('code')->toString(), 'login');
        } catch (InvalidMfaCodeException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        // No remember-me cookie: it would later start a session that never
        // passed the second factor.
        Auth::guard('web')->login($user);
        SecuritySession::signedIn($request, withMfa: true);

        return (new UserResource($user))->response();
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(status: 204);
    }

    public function me(Request $request): JsonResponse
    {
        return (new UserResource($request->user()))->response();
    }
}
