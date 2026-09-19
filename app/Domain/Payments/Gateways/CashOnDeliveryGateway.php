<?php

declare(strict_types=1);

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\PaymentStatus;

/**
 * Module 12 §15-17 "Cash on Delivery". "COD is a payment method, not
 * an online gateway" — initiate() makes no external call at all; the
 * Payment simply sits Pending until fulfillment/delivery, at which
 * point a STAFF member records collection via
 * PaymentService::recordManualConfirmation() (Module 12 §63-64) — not
 * a webhook (there is no provider to call back).
 */
final class CashOnDeliveryGateway implements PaymentGatewayContract
{
    public function method(): PaymentMethod
    {
        return PaymentMethod::CashOnDelivery;
    }

    public function initiate(Payment $payment): PaymentInitiationResult
    {
        return new PaymentInitiationResult(status: PaymentStatus::Pending);
    }

    public function verifyWebhookSignature(string $rawPayload, ?string $signatureHeader, string $storeSecret): bool
    {
        return false; // COD has no webhook concept — see class docblock
    }

    public function translateWebhookPayload(array $payload): array
    {
        throw new \LogicException('Cash on Delivery does not support webhooks.');
    }
}
