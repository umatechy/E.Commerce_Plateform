<?php

declare(strict_types=1);

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Identity\Http\Requests\LoginRequest;
use App\Domain\Identity\Http\Requests\RegisterRequest;
use App\Domain\Identity\Http\Resources\UserResource;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\InvalidMfaCodeException;
use App\Domain\Identity\Services\MfaService;
use App\Domain\Identity\Support\SecuritySession;
use App\Domain\Packages\Services\SubscriptionLifecycleService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreStatus;
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
    public function register(RegisterRequest $request, SubscriptionLifecycleService $subscriptions): JsonResponse
    {
        // Registration creates User + Store + owner Role + membership +
        // trial Subscription (Module 04 §18: "Every Store/Tenant must
        // have a current package entitlement" — B2 fix, see
        // docs/development/b2-inspection-findings.md item B: B1 left a
        // newly registered store with no Subscription row at all) as
        // ONE atomic unit — this is the one place in the platform where
        // no TenantContext exists yet (there is no store until this
        // transaction commits), so it deliberately does NOT go through
        // BelongsToTenant's auto-fill; store_id values are set explicitly.
        $user = DB::transaction(function () use ($request, $subscriptions) {
            $user = User::query()->create([
                'name' => $request->string('name')->toString(),
                'email' => $request->string('email')->toString(),
                'password' => $request->string('password')->toString(), // hashed via cast
            ]);

            $store = Store::query()->create([
                'name' => $request->string('store_name')->toString(),
                'slug' => Str::slug($request->string('store_name')->toString()).'-'.Str::lower(Str::random(6)),
                'status' => StoreStatus::PendingSetup,
            ]);

            // StoreObserver (registered in AppServiceProvider — see
            // docs/development/b1-inspection-findings.md) seeds the
            // default Owner/Manager/Staff roles for this store on
            // creation; we look up the seeded Owner role here rather
            // than creating a duplicate one.
            $ownerRole = Role::query()->withoutTenantScope()
                ->where('store_id', $store->id)
                ->where('slug', 'owner')
                ->firstOrFail();

            $store->users()->attach($user, ['role_id' => $ownerRole->id, 'status' => 'active']);

            $subscriptions->startTrial($store);

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
