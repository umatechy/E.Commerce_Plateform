<?php

declare(strict_types=1);

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\InvitationUnavailableException;
use App\Domain\Identity\Services\StoreTeamService;
use App\Domain\Identity\Services\TeamActionRefusedException;
use App\Domain\Packages\Exceptions\SubscriptionInactiveException;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/**
 * Module 02 §18 — the invitee's side (public, rate limited). Every call
 * carries the token from the email; without it nothing about the
 * invitation is revealed. Accepting as an existing account requires being
 * signed in to it (staff session); a new account is created otherwise and
 * signed in.
 */
final class InvitationController
{
    public function show(Request $request, StoreTeamService $team, string $invitation): JsonResponse
    {
        $token = (string) $request->validate(['token' => ['required', 'string', 'size:64']])['token'];

        try {
            $open = $team->findOpen($invitation, $token);
        } catch (InvitationUnavailableException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invitation_unavailable'], 404);
        }

        $signedIn = Auth::guard('web')->user();

        return response()->json(['data' => [
            'store' => Store::query()->whereKey($open->store_id)->value('name'),
            'role' => $open->role->name,
            'email' => $open->email,
            'has_account' => User::query()->where('email', $open->email)->exists(),
            'signed_in_as' => $signedIn instanceof User ? $signedIn->email : null,
            'expires_at' => $open->expires_at->toIso8601String(),
        ]]);
    }

    public function accept(Request $request, StoreTeamService $team, string $invitation): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'name' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'confirmed', Password::defaults()],
        ]);
        $signedIn = Auth::guard('web')->user();

        try {
            $user = $team->accept($invitation, $validated['token'], $signedIn instanceof User ? $signedIn : null, [
                'name' => $validated['name'] ?? null,
                'password' => $validated['password'] ?? null,
            ]);
        } catch (InvitationUnavailableException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invitation_unavailable'], 404);
        } catch (TeamActionRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode, 'errors' => [$e->field => [$e->getMessage()]]], 422);
        } catch (SubscriptionInactiveException) {
            return response()->json(['message' => 'This store cannot add team members right now. Ask the owner to check their subscription.', 'code' => 'subscription_inactive'], 403);
        }

        if (! $signedIn && $request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        return response()->json(['data' => ['store' => Store::query()->whereKey(app(\App\Domain\Tenancy\Support\TenantContext::class)->storeId())->value('name')]]);
    }
}
