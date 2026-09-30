<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccount\Http\Controllers;

use App\Domain\CustomerAccount\Services\CustomerPasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/** Phase B25 — storefront "forgot password" (see CustomerPasswordResetService). */
final class CustomerPasswordResetController
{
    public function __construct(private readonly CustomerPasswordResetService $resets) {}

    public function forgot(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $this->resets->request($request->attributes->get('storefront.store'), $validated['email'], $request->ip());

        // The same answer whether or not the account exists.
        return response()->json(['message' => 'If an account exists for this email, a reset link is on its way.'], 202);
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if (! $this->resets->reset($validated['email'], $validated['token'], $validated['password'])) {
            return response()->json(['message' => 'This reset link is invalid or has expired. Please request a new one.', 'code' => 'invalid_token'], 422);
        }

        return response()->json(['message' => 'Your password has been reset. You can sign in now.']);
    }
}
