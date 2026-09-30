<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Http\Controllers;

use App\Domain\Compliance\Exceptions\CustomerErasureBlockedException;
use App\Domain\Compliance\Policies\CompliancePolicy;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Compliance\Services\CustomerDataService;
use App\Domain\Orders\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Module 32 data-subject requests (Phase B22). {customer} is bound under
 * the tenant scope, so another store's customer is a 404 (ADR-001).
 * Every request is audited; the audit entry never copies the exported
 * personal data itself.
 */
final class CustomerPrivacyController
{
    public function export(Request $request, Customer $customer, CustomerDataService $data, AuditLogger $audit): JsonResponse
    {
        abort_unless(app(CompliancePolicy::class)->managePrivacy($request->user()), 403);

        $export = $data->export($customer);
        $audit->record('privacy.customer_data_exported', ['requested_by' => 'store_staff'], $customer);

        return response()->json(['data' => $export]);
    }

    public function erase(Request $request, Customer $customer, CustomerDataService $data, AuditLogger $audit): JsonResponse
    {
        abort_unless(app(CompliancePolicy::class)->managePrivacy($request->user()), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            // Irreversible, so the caller must restate whose data this is.
            'confirm_email' => ['required', 'string'],
        ]);

        if ($customer->erased_at !== null) {
            return response()->json(['message' => 'This customer\'s personal data has already been erased.', 'code' => 'already_erased'], 409);
        }

        if (strcasecmp($validated['confirm_email'], $customer->email) !== 0) {
            throw ValidationException::withMessages(['confirm_email' => 'This does not match the customer\'s email address.']);
        }

        try {
            $summary = $data->erase($customer);
        } catch (CustomerErasureBlockedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'open_orders', 'open_orders' => $e->openOrders], 409);
        }

        $audit->record('privacy.customer_erased', ['reason' => $validated['reason'], ...$summary], $customer);

        return response()->json(['data' => $summary]);
    }

    /** The authenticated customer's own data (right of access, self-service). */
    public function exportOwn(Request $request, CustomerDataService $data, AuditLogger $audit): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $export = $data->export($customer);
        $audit->record('privacy.customer_data_exported', ['requested_by' => 'customer'], $customer);

        return response()->json(['data' => $export]);
    }
}
