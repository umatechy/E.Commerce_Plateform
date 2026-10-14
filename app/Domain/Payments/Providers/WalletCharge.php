<?php

declare(strict_types=1);

namespace App\Domain\Payments\Providers;

use Carbon\CarbonImmutable;

/**
 * One charge as the provider sees it: our reference, the server-decided
 * amount and currency (never the client's — Module 12 §11–12), and the
 * shopper's wallet details for the first request only (inquiries and
 * refunds do not need them and do not get them).
 */
final class WalletCharge
{
    public function __construct(
        public readonly string $reference,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $description,
        public readonly CarbonImmutable $createdAt,
        public readonly CarbonImmutable $expiresAt,
        public readonly ?string $mobile = null,
        public readonly ?string $cnicLast6 = null,
        public readonly ?string $payerHint = null,
        public readonly ?string $providerTransactionReference = null,
    ) {}

    /** Major units with two decimals, as wallet providers take PKR: 150000 → "1500.00". */
    public function amountDecimal(): string
    {
        return number_format($this->amountMinor / 100, 2, '.', '');
    }
}
