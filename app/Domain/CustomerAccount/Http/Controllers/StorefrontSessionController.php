<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccount\Http\Controllers;

use App\Domain\Cart\Services\CartService;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\CustomerAccount\Services\CustomerRegistration;
use App\Domain\CustomerAccount\Services\StorefrontSession;
use App\Domain\Orders\Http\Requests\LoginCustomerRequest;
use App\Domain\Orders\Http\Requests\RegisterCustomerRequest;
use App\Domain\Orders\Http\Resources\CustomerResource;
use App\Domain\Orders\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase B25 — sign in, sign up and sign out on the web storefront. Same
 * rules as the token API (LoginCustomerRequest's throttling and audit,
 * CustomerRegistration), but the token goes into an HttpOnly cookie and
 * never into the response body (see StorefrontSession).
 */
final class StorefrontSessionController
{
    public function __construct(private readonly StorefrontSession $session) {}

    public function login(LoginCustomerRequest $request, CartService $carts): JsonResponse
    {
        $customer = $request->authenticateCustomer();
        app(AuditLogger::class)->record('auth.login.succeeded', ['guard' => 'customer', 'via' => 'storefront'], $customer, $customer->store_id, $customer);

        return $this->signedIn($request, $customer, $carts, 200);
    }

    public function register(RegisterCustomerRequest $request, CustomerRegistration $registration, CartService $carts): JsonResponse
    {
        $customer = $registration->register([
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => $request->string('password')->toString(),
            'phone' => $request->input('phone'),
        ]);

        return $this->signedIn($request, $customer, $carts, 201);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $customer->currentAccessToken()->delete();
        app(AuditLogger::class)->record('auth.logout', ['guard' => 'customer', 'via' => 'storefront'], $customer);

        return response()->json(status: 204)->withCookie($this->session->forget($request));
    }

    private function signedIn(Request $request, Customer $customer, CartService $carts, int $status): JsonResponse
    {
        // Module 11 §22 "Cart Merge": the guest cart comes along.
        if ($guestToken = $request->header('X-Guest-Cart-Token')) {
            $carts->mergeGuestCartIntoCustomer($guestToken, $customer);
        }

        return (new CustomerResource($customer))->response()->setStatusCode($status)
            ->withCookie($this->session->cookie($request, $this->session->issue($customer)));
    }
}
