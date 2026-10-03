<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccount\Http\Controllers;

use App\Domain\Customers\Services\CustomerEmailVerification;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 10 §9, §13 and SRS AUTH-002 (Phase B32): a customer confirms
 * their email address. Sending needs the customer's own session;
 * confirming needs only the link (the customer may open it on another
 * device). The answer never says whether an address belongs to anyone.
 */
final class CustomerEmailVerificationController
{
    public function send(Request $request, CustomerEmailVerification $verification): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        if ($customer->email_verified_at !== null) {
            return response()->json(['data' => ['email_verified' => true]]);
        }

        // false: a link went out less than a minute ago; that one still works.
        $sent = $verification->send($customer, Store::query()->findOrFail($customer->store_id));

        return response()->json(['data' => ['email_verified' => false, 'sent' => $sent]], 202);
    }

    public function verify(Request $request, CustomerEmailVerification $verification): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'size:64'],
        ]);

        $customer = $verification->verify($data['email'], $data['token']);
        if ($customer === null) {
            return response()->json(['message' => 'This link is not valid any more. Sign in and ask for a new one.', 'code' => 'verification_invalid'], 422);
        }

        return response()->json(['data' => ['email_verified' => true]]);
    }
}
