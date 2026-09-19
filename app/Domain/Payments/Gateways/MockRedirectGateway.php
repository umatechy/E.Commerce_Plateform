<?php

declare(strict_types=1);

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\PaymentStatus;
use App\Domain\Payments\Models\TransactionStatus;
use App\Domain\Payments\Models\TransactionType;

/**
 * A TEST-MODE-ONLY stand-in for a redirect+webhook-based provider
 * (JazzCash/Easypaisa/future cards — Module 12 §20-22). No real HTTP
 * call to any external provider is ever made by this class — see
 * docs/development/b7-inspection-findings.md "Scope Decision": B7
 * builds the redirect+webhook+signature-verification SHAPE that a real
 * provider integration will need, using this deterministic double,
 * rather than fabricating live gateway responses (this milestone's
 * explicit prohibition).
 *
 * SIGNATURE SCHEME (Module 12 §30): HMAC-SHA256 of the raw request
 * body, keyed by the STORE's own `payment_webhook_secret` (Phase B7,
 * never exposed via any API). A real provider integration would swap
 * this for that provider's actual documented signature scheme without
 * changing anything outside this one class.
 */
final class MockRedirectGateway implements PaymentGatewayContract
{
    public function method(): PaymentMethod
    {
        return PaymentMethod::MockRedirect;
    }

    public function initiate(Payment $payment): PaymentInitiationResult
    {
        // A real adapter would call the provider's "create payment
        // intent" API here and use ITS returned reference/redirect URL.
        // This mock deterministically derives both from the payment's
        // own public_id so tests can construct valid follow-up webhook
        // payloads without any network call.
        $providerReference = 'mock_intent_'.$payment->public_id;

        return new PaymentInitiationResult(
            status: PaymentStatus::RequiresAction,
            redirectUrl: "https://mock-gateway.test/pay/{$providerReference}",
            providerPaymentReference: $providerReference,
            metadata: ['test_mode' => true],
        );
    }

    public function verifyWebhookSignature(string $rawPayload, ?string $signatureHeader, string $storeSecret): bool
    {
        if ($signatureHeader === null || $storeSecret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawPayload, $storeSecret);

        // Constant-time comparison — Module 12 §30 "never trust a
        // client-generated signature" implies the comparison itself
        // must not leak timing information about how much of the
        // signature matched.
        return hash_equals($expected, $signatureHeader);
    }

    public function translateWebhookPayload(array $payload): array
    {
        $outcome = $payload['outcome'] ?? null; // 'succeeded' | 'failed', set by the (mock) provider

        return match ($outcome) {
            'succeeded' => [
                'type' => TransactionType::Sale,
                'status' => TransactionStatus::Succeeded,
                'amount_minor' => (int) ($payload['amount_minor'] ?? 0),
                'provider_transaction_reference' => $payload['transaction_reference'] ?? null,
                'failure_code' => null,
                'failure_reason' => null,
            ],
            'failed' => [
                'type' => TransactionType::Sale,
                'status' => TransactionStatus::Failed,
                'amount_minor' => (int) ($payload['amount_minor'] ?? 0),
                'provider_transaction_reference' => $payload['transaction_reference'] ?? null,
                'failure_code' => $payload['failure_code'] ?? 'unknown',
                'failure_reason' => $payload['failure_reason'] ?? 'The provider reported a failed payment.',
            ],
            default => throw new \InvalidArgumentException("Unrecognized mock gateway webhook outcome: ".json_encode($outcome)),
        };
    }
}
