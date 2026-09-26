<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\Payments\Models\PaymentTransaction;
use App\Domain\Payments\Models\TransactionStatus;
use Illuminate\Http\JsonResponse;

/**
 * Module 30 §16 "Payment/Gateway Oversight" — read-only, cross-store.
 * Never exposes provider secrets/credentials (only already-public-to-
 * staff PaymentTransaction fields — failure_code/failure_reason,
 * never a raw gateway payload). Never mutates payment state — B7's
 * PaymentService/PaymentStateMachine remain fully authoritative.
 */
final class SuperAdminPaymentController
{
    public function failures(): JsonResponse
    {
        $failures = PaymentTransaction::query()->withoutTenantScope()
            ->where('status', TransactionStatus::Failed)
            ->where('created_at', '>=', now()->subDays(7))
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json(['data' => $failures]);
    }
}
