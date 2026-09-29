<?php

declare(strict_types=1);

namespace App\Domain\Orders\Http\Controllers;

use App\Domain\Cart\Services\CartService;
use App\Domain\Orders\Http\Requests\LoginCustomerRequest;
use App\Domain\Orders\Http\Requests\RegisterCustomerRequest;
use App\Domain\Orders\Http\Resources\CustomerResource;
use App\Domain\Orders\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Module 10 §10-11 "Customer Registration / Customer Login". Uses the
 * `customer` Sanctum guard exclusively (see config/auth.php,
 * docs/development/b6-inspection-findings.md) — structurally separate
 * from App\Domain\Identity\Http\Controllers\AuthController (staff).
 * No JWT/OAuth introduced (this milestone's explicit non-negotiable #6).
 */
final class CustomerAuthController
{
    public function register(RegisterCustomerRequest $request): JsonResponse
    {
        // Module 10 §12-style uniqueness: only among REGISTERED
        // accounts (password IS NOT NULL) — a guest Customer row from a
        // past order (Phase B5) never blocks a new registration with
        // the same email, per Module 09 §8's "guest orders should not
        // require a permanent account" and the migration's documented
        // decision.
        $exists = Customer::query()
            ->where('email', $request->string('email')->toString())
            ->whereNotNull('password')
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['email' => 'An account with this email already exists.']);
        }

        $customer = Customer::query()->create([
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => $request->string('password')->toString(),
            'phone' => $request->input('phone'),
        ]);

        $token = $customer->createToken('customer-api')->plainTextToken;

        return response()->json([
            'data' => new CustomerResource($customer),
            'token' => $token,
        ], 201);
    }

    public function login(LoginCustomerRequest $request, CartService $carts): JsonResponse
    {
        $customer = $request->authenticateCustomer();
        $token = $customer->createToken('customer-api')->plainTextToken;

        // Module 11 §22 "Cart Merge" — if the client presents a guest
        // cart token from the pre-login session, merge it now.
        if ($guestToken = $request->header('X-Guest-Cart-Token')) {
            $carts->mergeGuestCartIntoCustomer($guestToken, $customer);
        }

        return response()->json([
            'data' => new CustomerResource($customer),
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(status: 204);
    }

    public function me(Request $request): JsonResponse
    {
        return (new CustomerResource($request->user()))->response();
    }
}
