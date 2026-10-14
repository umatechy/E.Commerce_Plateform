<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

/**
 * Module 12 §3/§15/§18/§20-21.
 *
 * - cod, bank_transfer: manual methods (Phase B7).
 * - mock_redirect: Phase B7's test-only redirect double, kept for its tests.
 * - jazzcash, easypaisa: mobile-wallet payments through the store's own
 *   merchant account (Phase B48); test_wallet: the same flow against the
 *   "Test wallet" provider, which exists only outside production.
 */
enum PaymentMethod: string
{
    case CashOnDelivery = 'cod';
    case BankTransfer = 'bank_transfer';
    case MockRedirect = 'mock_redirect';
    case JazzCash = 'jazzcash';
    case Easypaisa = 'easypaisa';
    case TestWallet = 'test_wallet';

    /** The online provider behind a wallet method; null for the others. */
    public function walletProvider(): ?string
    {
        return match ($this) {
            self::JazzCash => 'jazzcash',
            self::Easypaisa => 'easypaisa',
            self::TestWallet => 'simulator',
            default => null,
        };
    }

    public static function forWalletProvider(string $provider): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->walletProvider() === $provider) {
                return $case;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function walletValues(): array
    {
        return array_values(array_map(fn (self $m) => $m->value, array_filter(self::cases(), fn (self $m) => $m->walletProvider() !== null)));
    }
}
