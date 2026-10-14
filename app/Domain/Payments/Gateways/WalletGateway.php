<?php

declare(strict_types=1);

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\PaymentStatus;

/**
 * Phase B48: the order-side half of a mobile-wallet payment (JazzCash,
 * Easypaisa, Test wallet). Creating the Payment calls no provider: it only
 * waits for the shopper (RequiresAction). The money is asked for by
 * OnlinePaymentService, one PaymentAttempt at a time, and its answers come
 * in through that service — never through the B7 webhook translation below.
 */
final class WalletGateway implements PaymentGatewayContract
{
    public function __construct(private readonly PaymentMethod $method) {}

    public function method(): PaymentMethod
    {
        return $this->method;
    }

    public function initiate(Payment $payment): PaymentInitiationResult
    {
        return new PaymentInitiationResult(status: PaymentStatus::RequiresAction, metadata: ['provider' => $this->method->walletProvider()]);
    }

    public function verifyWebhookSignature(string $rawPayload, ?string $signatureHeader, string $storeSecret): bool
    {
        return false; // provider notifications go to OnlinePaymentService::handleNotification()
    }

    public function translateWebhookPayload(array $payload): array
    {
        throw new \LogicException('Wallet payments are not settled through the B7 webhook path.');
    }
}
