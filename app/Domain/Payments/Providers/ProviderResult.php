<?php

declare(strict_types=1);

namespace App\Domain\Payments\Providers;

/**
 * A provider's answer in platform terms (Module 12 §7 "provider-specific
 * states must map into the platform state model", §59 error model).
 *
 * `providerMessage` is for staff screens only; shoppers see the message of
 * the failure category (FailureCategory::customerMessage()).
 */
final class ProviderResult
{
    public function __construct(
        public readonly ProviderOutcome $outcome,
        public readonly ?FailureCategory $category = null,
        public readonly ?string $providerCode = null,
        public readonly ?string $providerMessage = null,
        public readonly ?string $providerTransactionReference = null,
        public readonly ?int $amountMinor = null,
        public readonly ?string $currency = null,
    ) {}

    public static function unknown(FailureCategory $category, ?string $message = null): self
    {
        return new self(ProviderOutcome::Unknown, $category, null, $message);
    }
}
