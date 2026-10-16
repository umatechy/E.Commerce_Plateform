<?php

declare(strict_types=1);

namespace App\Domain\Resellers\Http\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\SecuritySession;
use App\Domain\Resellers\Services\ResellerDirectory;
use App\Domain\Resellers\Services\ResellerRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/**
 * Phase B58 (Module 28 §92, §117) — becoming a partner:
 *
 * - apply: the public partner page. The same answer whether or not the
 *   address is known; honeypot and throttle against bots.
 * - invitation: what an invitation link is for (its token is sent in the
 *   body, never in a URL), whether an account with that address exists,
 *   and whether it is the one signed in.
 * - accept: the signed-in account of the invited address accepts.
 * - register: no account yet — make one with the invited address and
 *   accept. Two-step sign-in is then required before the portal opens.
 */
final class PartnerJoinController
{
    public function __construct(private readonly ResellerDirectory $directory) {}

    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'min:2', 'max:160'],
            'contact_name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'size:2'],
            'website' => ['nullable', 'url', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'accept_terms' => ['accepted'],
            'fax' => ['nullable', 'string', 'max:0'], // honeypot: people never see this field
        ]);
        $this->directory->apply($data);

        return response()->json(['message' => 'Thank you. Our team reviews each application; if it is approved, we email you an invitation to the partner portal.'], 202);
    }

    public function invitation(Request $request): JsonResponse
    {
        $reseller = $this->directory->invitation((string) $request->input('token', ''));
        abort_if($reseller === null, 404, 'This invitation does not work any more. Ask Umar Techy for a new one.');
        $user = $request->user();

        return response()->json(['data' => [
            'business_name' => $reseller->business_name,
            'email' => $reseller->email,
            'tier' => $reseller->tier?->name,
            'account_exists' => User::query()->where('email', $reseller->email)->exists(),
            'signed_in_as' => $user?->email,
            'signed_in_matches' => $user !== null && mb_strtolower($user->email) === $reseller->email,
        ]]);
    }

    public function accept(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string', 'max:100']]);
        /** @var User $user */
        $user = $request->user();

        try {
            $this->directory->accept((string) $request->input('token'), $user);
        } catch (ResellerRefused $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
        }

        return response()->json(['message' => 'Welcome. Your partner portal is ready.', 'data' => ['mfa_required' => ! $user->hasMfaEnabled()]]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);
        $reseller = $this->directory->invitation($data['token']);
        abort_if($reseller === null, 404, 'This invitation does not work any more. Ask Umar Techy for a new one.');
        if (User::query()->where('email', $reseller->email)->exists()) {
            return response()->json(['message' => 'An account with this email already exists. Sign in with it to accept.', 'code' => 'account_exists'], 422);
        }

        try {
            $user = DB::transaction(function () use ($data, $reseller) {
                $user = User::query()->create(['name' => $data['name'], 'email' => $reseller->email, 'password' => $data['password']]);
                $this->directory->accept($data['token'], $user);

                return $user;
            });
        } catch (ResellerRefused $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
        }

        Auth::guard('web')->login($user);
        SecuritySession::signedIn($request, withMfa: false);

        return response()->json(['message' => 'Your account is ready. Turn on two-step sign-in to open the partner portal.', 'data' => ['mfa_required' => true]], 201);
    }
}
