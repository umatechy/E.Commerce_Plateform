<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Domain\Payments\Gateways\GatewayResolver;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\TransactionStatus;
use Tests\TestCase;

/**
 * Phase B7 — Gateway abstraction + adapter behavior (Module 12 §4-5,
 * Step 5-6). Pure unit tests — no database required except where a
 * Payment model is instantiated in-memory (not persisted).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class GatewayTest extends TestCase
{
    public function test_resolver_returns_the_correct_adapter_per_method(): void
    {
        $resolver = new GatewayResolver();

        $this->assertSame(PaymentMethod::CashOnDelivery, $resolver->resolve(PaymentMethod::CashOnDelivery)->method());
        $this->assertSame(PaymentMethod::BankTransfer, $resolver->resolve(PaymentMethod::BankTransfer)->method());
        $this->assertSame(PaymentMethod::MockRedirect, $resolver->resolve(PaymentMethod::MockRedirect)->method());
    }

    public function test_cod_and_bank_transfer_report_no_webhook_support(): void
    {
        $resolver = new GatewayResolver();

        $this->assertFalse($resolver->resolve(PaymentMethod::CashOnDelivery)->verifyWebhookSignature('{}', 'sig', 'secret'));
        $this->assertFalse($resolver->resolve(PaymentMethod::BankTransfer)->verifyWebhookSignature('{}', 'sig', 'secret'));
    }

    public function test_mock_gateway_accepts_a_correctly_signed_payload(): void
    {
        $gateway = (new GatewayResolver())->resolveByProviderName('mock_redirect');
        $secret = 'test-secret';
        $payload = '{"outcome":"succeeded"}';
        $signature = hash_hmac('sha256', $payload, $secret);

        $this->assertTrue($gateway->verifyWebhookSignature($payload, $signature, $secret));
    }

    public function test_mock_gateway_rejects_an_incorrectly_signed_payload(): void
    {
        $gateway = (new GatewayResolver())->resolveByProviderName('mock_redirect');
        $payload = '{"outcome":"succeeded"}';

        $this->assertFalse($gateway->verifyWebhookSignature($payload, 'not-the-real-signature', 'test-secret'));
    }

    public function test_mock_gateway_rejects_a_payload_signed_with_the_wrong_secret(): void
    {
        $gateway = (new GatewayResolver())->resolveByProviderName('mock_redirect');
        $payload = '{"outcome":"succeeded"}';
        $signatureFromWrongSecret = hash_hmac('sha256', $payload, 'someone-elses-secret');

        $this->assertFalse($gateway->verifyWebhookSignature($payload, $signatureFromWrongSecret, 'this-stores-real-secret'));
    }

    public function test_mock_gateway_translates_a_succeeded_payload_correctly(): void
    {
        $gateway = (new GatewayResolver())->resolveByProviderName('mock_redirect');

        $translated = $gateway->translateWebhookPayload([
            'outcome' => 'succeeded', 'amount_minor' => 5000, 'transaction_reference' => 'txn_123',
        ]);

        $this->assertSame(TransactionStatus::Succeeded, $translated['status']);
        $this->assertSame(5000, $translated['amount_minor']);
    }

    public function test_mock_gateway_translates_a_failed_payload_correctly(): void
    {
        $gateway = (new GatewayResolver())->resolveByProviderName('mock_redirect');

        $translated = $gateway->translateWebhookPayload([
            'outcome' => 'failed', 'amount_minor' => 5000, 'failure_code' => 'insufficient_funds',
        ]);

        $this->assertSame(TransactionStatus::Failed, $translated['status']);
        $this->assertSame('insufficient_funds', $translated['failure_code']);
    }
}
