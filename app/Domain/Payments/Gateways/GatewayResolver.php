<?php

declare(strict_types=1);

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\Models\PaymentMethod;

/**
 * The ONE place PaymentMethod maps to a concrete gateway. Adding a
 * real provider later means adding one case here and a new class
 * implementing PaymentGatewayContract — nothing else in the codebase
 * changes (Module 12 Final Rule #28).
 */
final class GatewayResolver
{
    public function resolve(PaymentMethod $method): PaymentGatewayContract
    {
        return match ($method) {
            PaymentMethod::CashOnDelivery => new CashOnDeliveryGateway(),
            PaymentMethod::BankTransfer => new BankTransferGateway(),
            PaymentMethod::MockRedirect => new MockRedirectGateway(),
        };
    }

    public function resolveByProviderName(string $provider): PaymentGatewayContract
    {
        return match ($provider) {
            'mock_redirect' => new MockRedirectGateway(),
            default => throw new \InvalidArgumentException("Unknown payment provider: {$provider}"),
        };
    }
}
