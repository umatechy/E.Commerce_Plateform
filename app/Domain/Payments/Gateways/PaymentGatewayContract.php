<?php

declare(strict_types=1);

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;

/**
 * Module 12 §4 "Provider Abstraction" / Final Rule #2: "Payment
 * providers must use a common abstraction." OrderService/CheckoutService
 * depend ONLY on this interface — never on a concrete gateway class,
 * so a new provider can be added (Final Rule #28: "New providers must
 * be addable without rewriting Checkout") by implementing this
 * contract and registering it, with zero changes to OrderService,
 * CheckoutService, or PaymentService's calling code.
 *
 * Only the operations B7's actual scope requires are defined here
 * (Step 5: "do not implement unsupported operations merely because a
 * generic gateway interface normally contains them").
 */
interface PaymentGatewayContract
{
    public function method(): PaymentMethod;

    /**
     * Called once, immediately after a Payment record is created
     * (server-authoritative amount already resolved — see
     * PaymentService::createForOrder()). Never receives or trusts any
     * client-supplied amount/currency.
     */
    public function initiate(Payment $payment): PaymentInitiationResult;

    /**
     * Module 12 §30 "Payment Signature Validation". Returns false for
     * gateways with no webhook concept at all (COD, Bank Transfer —
     * both are staff-confirmed manually, never via an external
     * callback), which correctly means their routes never accept
     * unsigned/unverifiable webhook traffic.
     */
    public function verifyWebhookSignature(string $rawPayload, ?string $signatureHeader, string $storeSecret): bool;

    /**
     * Translates an ALREADY-VERIFIED webhook payload into the
     * transaction PaymentService should record. Never called unless
     * verifyWebhookSignature() returned true for this exact payload.
     *
     * @return array{type: \App\Domain\Payments\Models\TransactionType, status: \App\Domain\Payments\Models\TransactionStatus, amount_minor: int, provider_transaction_reference: ?string, failure_code: ?string, failure_reason: ?string}
     */
    public function translateWebhookPayload(array $payload): array;
}
