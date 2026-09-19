<?php

declare(strict_types=1);

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Identity\Http\Requests\LoginRequest;
use App\Domain\Identity\Http\Requests\RegisterRequest;
use App\Domain\Identity\Http\Resources\UserResource;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Services\SubscriptionLifecycleService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
                'name' => $request->string('name'),
                'email' => $request->string('email'),
                'password' => $request->string('password'), // hashed via cast
            ]);

            $store = Store::query()->create([
                'name' => $request->string('store_name'),
                'slug' => Str::slug($request->string('store_name')).'-'.Str::lower(Str::random(6)),
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

        Auth::login($user);
        $request->session()->regenerate();

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        return (new UserResource(Auth::user()))->response();
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(status: 204);
    }

    public function me(Request $request): JsonResponse
    {
        return (new UserResource($request->user()))->response();
    }
}
