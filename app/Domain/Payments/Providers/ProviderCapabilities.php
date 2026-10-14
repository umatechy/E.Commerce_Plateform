<?php

declare(strict_types=1);

namespace App\Domain\Payments\Providers;

/**
 * Module 12 §86 "Provider Capability Matrix": what an adapter can actually
 * do, as built and as known. Checkout and the refund screen read this and
 * never call an operation that is not declared (Module 12 §86, §44).
 *
 * `verified` says whether the integration has been checked against the
 * provider's sandbox. Until it has, the platform keeps the provider out of
 * production (`payments.live_providers`), whatever a store asks.
 */
final class ProviderCapabilities
{
    /** @param list<string> $currencies */
    public function __construct(
        public readonly bool $walletPayment,
        public readonly bool $cardPayment,
        public readonly bool $statusInquiry,
        public readonly bool $refund,
        public readonly bool $partialRefund,
        public readonly bool $notifications,
        public readonly bool $signedNotifications,
        public readonly array $currencies,
        public readonly bool $verified,
        public readonly ?string $note = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'wallet_payment' => $this->walletPayment,
            'card_payment' => $this->cardPayment,
            'status_inquiry' => $this->statusInquiry,
            'refund' => $this->refund,
            'partial_refund' => $this->partialRefund,
            'notifications' => $this->notifications,
            'signed_notifications' => $this->signedNotifications,
            'currencies' => $this->currencies,
            'verified' => $this->verified,
            'note' => $this->note,
        ];
    }
}
