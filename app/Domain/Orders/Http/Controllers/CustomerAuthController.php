<?php

declare(strict_types=1);

namespace App\Domain\Orders\Http\Controllers;

use App\Domain\Cart\Services\CartService;
use App\Domain\CustomerAccount\Services\CustomerRegistration;
use App\Domain\Orders\Http\Requests\LoginCustomerRequest;
use App\Domain\Orders\Http\Requests\RegisterCustomerRequest;
use App\Domain\Orders\Http\Resources\CustomerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 10 §10-11 "Customer Registration / Customer Login". Uses the
 * `customer` Sanctum guard exclusively (see config/auth.php,
 * docs/development/b6-inspection-findings.md) — structurally separate
 * from App\Domain\Identity\Http\Controllers\AuthController (staff).
 * No JWT/OAuth introduced (this milestone's explicit non-negotiable #6).
 */
final class CustomerAuthController
{
    public function register(RegisterCustomerRequest $request, CustomerRegistration $registration): JsonResponse
    {
        $customer = $registration->register([
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
        app(\App\Domain\Compliance\Services\AuditLogger::class)->record('auth.login.succeeded', ['guard' => 'customer'], $customer, $customer->store_id, $customer);

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
        app(\App\Domain\Compliance\Services\AuditLogger::class)->record('auth.logout', ['guard' => 'customer'], $request->user());

        return response()->json(status: 204);
    }

    public function me(Request $request): JsonResponse
    {
        return (new CustomerResource($request->user()))->response();
    }
}
