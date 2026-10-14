<?php

declare(strict_types=1);

namespace App\Domain\Payments\Providers;

/**
 * "Test wallet": a provider that moves no money and calls nothing, so the
 * whole online-payment flow (approval wait, failure, retry, timeout,
 * reconciliation, refund, signed notifications) can be used and tested
 * before any real provider credentials exist (owner decision 2: none are
 * invented).
 *
 * Offered only where config('payments.simulator') is on — local and test
 * environments — and never in production (ProviderRegistry). It is shown to
 * shoppers as "Test wallet (no real money)".
 *
 * The last four digits of the mobile number choose what happens:
 *   …0002 declined · …0003 pending, then paid on inquiry ·
 *   …0004 no answer (unknown), then paid on inquiry ·
 *   …0005 stays pending · …0006 paid, but one paisa short · anything else paid.
 */
final class SimulatorAdapter implements ProviderAdapter
{
    public const SIGNATURE_HEADER = 'x-simulator-signature';

    public const TIMESTAMP_HEADER = 'x-simulator-timestamp';

    public function key(): string
    {
        return 'simulator';
    }

    public function label(): string
    {
        return 'Test wallet';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            walletPayment: true, cardPayment: false, statusInquiry: true,
            refund: true, partialRefund: true,
            notifications: true, signedNotifications: true,
            currencies: ['PKR', 'USD'], verified: true,
            note: 'Moves no money. Local and test environments only.',
        );
    }

    public function credentialFields(): array
    {
        return [
            new CredentialField('merchant_id', 'Test merchant ID', secret: false, maxLength: 40),
            new CredentialField('secret', 'Test signing secret', secret: true, maxLength: 100),
        ];
    }

    public function payerFields(): array
    {
        return [new PayerField(PayerField::MOBILE, 'Test wallet number', '03XX XXXXXXX')];
    }

    public function chargeWallet(WalletCharge $charge, ProviderCredentials $credentials): ProviderResult
    {
        return match ($this->scenario($charge)) {
            '0002' => new ProviderResult(ProviderOutcome::Failed, FailureCategory::PaymentDeclined, 'SIM-DECLINED', 'The test wallet declined the payment.'),
            '0003', '0005' => new ProviderResult(ProviderOutcome::Pending, null, 'SIM-PENDING', 'Waiting for approval.'),
            '0004' => ProviderResult::unknown(FailureCategory::ProviderUnavailable, 'No answer from the test wallet.'),
            '0006' => $this->paid($charge, $charge->amountMinor - 1),
            default => $this->paid($charge, $charge->amountMinor),
        };
    }

    public function inquire(WalletCharge $charge, ProviderCredentials $credentials): ProviderResult
    {
        return match ($this->scenario($charge)) {
            '0002' => new ProviderResult(ProviderOutcome::Failed, FailureCategory::PaymentDeclined, 'SIM-DECLINED', 'The test wallet declined the payment.'),
            '0005' => new ProviderResult(ProviderOutcome::Pending, null, 'SIM-PENDING', 'Waiting for approval.'),
            '0006' => $this->paid($charge, $charge->amountMinor - 1),
            default => $this->paid($charge, $charge->amountMinor),
        };
    }

    public function refund(WalletCharge $charge, int $amountMinor, string $refundReference, ProviderCredentials $credentials): ProviderResult
    {
        return new ProviderResult(ProviderOutcome::Succeeded, null, 'SIM-REFUNDED', 'Refunded by the test wallet.', 'SIMR-'.strtoupper(substr(hash('sha256', $refundReference), 0, 12)), $amountMinor, $charge->currency);
    }

    public function notificationReference(array $input, array $headers): ?string
    {
        return isset($input['reference']) && is_string($input['reference']) && $input['reference'] !== '' ? $input['reference'] : null;
    }

    public function verifyNotification(string $rawBody, array $input, array $headers, ProviderCredentials $credentials): bool
    {
        $signature = $headers[self::SIGNATURE_HEADER] ?? null;
        $timestamp = $headers[self::TIMESTAMP_HEADER] ?? null;
        if (! is_string($signature) || ! is_string($timestamp) || $credentials->get('secret') === '') {
            return false;
        }

        return hash_equals(self::sign($timestamp, $rawBody, $credentials->get('secret')), $signature);
    }

    public function notificationSentAt(array $input, array $headers): ?int
    {
        $timestamp = $headers[self::TIMESTAMP_HEADER] ?? null;

        return is_string($timestamp) && ctype_digit($timestamp) ? (int) $timestamp : null;
    }

    /** The timestamp is signed with the body, so an old notice cannot be sent again with a new time. */
    public static function sign(string $timestamp, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    private function scenario(WalletCharge $charge): string
    {
        return substr((string) ($charge->mobile ?? $charge->payerHint ?? ''), -4);
    }

    private function paid(WalletCharge $charge, int $amountMinor): ProviderResult
    {
        return new ProviderResult(ProviderOutcome::Succeeded, null, 'SIM-PAID', 'Paid with the test wallet.', 'SIM-'.strtoupper(substr(hash('sha256', $charge->reference), 0, 12)), $amountMinor, $charge->currency);
    }
}
