<?php

declare(strict_types=1);

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\Models\PaymentStatus;

/**
 * The result of PaymentGatewayContract::initiate(). A plain value
 * object so every adapter returns the same shape regardless of
 * provider (Module 12 §4 "Provider Abstraction").
 */
final class PaymentInitiationResult
{
    public function __construct(
        public readonly PaymentStatus $status,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $providerPaymentReference = null,
        public readonly array $metadata = [],
    ) {}
}
