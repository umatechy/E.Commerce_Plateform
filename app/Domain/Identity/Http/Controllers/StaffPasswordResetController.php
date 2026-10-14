<?php

declare(strict_types=1);

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Identity\Services\StaffPasswordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/** Phase B52 (Module 32 §7.5–7.6): "forgot password" for staff accounts (see StaffPasswordService). */
final class StaffPasswordResetController
{
    public function __construct(private readonly StaffPasswordService $passwords) {}

    public function forgot(Request $request): JsonResponse
    {
        $email = $request->validate(['email' => ['required', 'string', 'email', 'max:255']])['email'];
        $this->passwords->requestReset($email, $request->ip());

        // The same answer whether or not the account exists.
        return response()->json(['message' => 'If an account exists for this email, a reset link is on its way.'], 202);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);
        if (! $this->passwords->reset($data['email'], $data['token'], $data['password'])) {
            return response()->json(['message' => 'This reset link is invalid or has expired. Ask for a new one.', 'code' => 'invalid_token'], 422);
        }

        return response()->json(['message' => 'Your password was reset. You can sign in now.']);
    }
}
