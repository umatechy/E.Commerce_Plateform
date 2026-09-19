<?php

declare(strict_types=1);

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\PaymentStatus;

/**
 * Module 12 §18-19 "Bank Transfer / Bank Transfer Verification".
 * initiate() records the store's bank details for the customer to see
 * (returned as display metadata, never a redirect) and leaves the
 * Payment Pending until a STAFF member manually verifies the transfer
 * arrived — Final Architectural Rule #19: "Bank-transfer confirmation
 * must require authorized verification," via
 * PaymentService::recordManualConfirmation(), never a webhook.
 */
final class BankTransferGateway implements PaymentGatewayContract
{
    public function method(): PaymentMethod
    {
        return PaymentMethod::BankTransfer;
    }

    public function initiate(Payment $payment): PaymentInitiationResult
    {
        return new PaymentInitiationResult(
            status: PaymentStatus::Pending,
            metadata: [
                // Placeholder store bank details — a real
                // "store-configurable bank account" feature (Module 12
                // §31's "Store-Specific Provider Accounts" concept) is
                // not built in B7; this documents the integration point
                // without inventing a full bank-account-management UI.
                'instructions' => 'Please transfer the exact order amount to the store\'s bank account and share the reference number with support.',
            ],
        );
    }

    public function verifyWebhookSignature(string $rawPayload, ?string $signatureHeader, string $storeSecret): bool
    {
        return false; // Bank Transfer has no webhook concept — manual staff verification only
    }

    public function translateWebhookPayload(array $payload): array
    {
        throw new \LogicException('Bank Transfer does not support webhooks.');
    }
}
