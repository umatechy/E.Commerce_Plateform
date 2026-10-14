<?php

declare(strict_types=1);

namespace App\Domain\Payments\Providers;

/**
 * Easypaisa mobile-account payments (owner decision 2, Module 12 §21).
 *
 * Integration used: Easypaisa's server-to-server REST API for mobile
 * account ("MA") transactions and its transaction inquiry. The shopper gives
 * the wallet number and approves on the phone; nothing passes through the
 * browser.
 *
 * NOT YET VERIFIED. Easypaisa hands its API guide to merchants on
 * onboarding; the paths, the "Credentials" header (Base64 of
 * username:password) and the status values below follow that guide as it
 * is commonly published and must be confirmed in the Easypaisa sandbox with
 * real sandbox credentials before any store can take real money
 * (`payments.live_providers`, Super Admin). Easypaisa notifications are not
 * signed: a notification only tells us which payment to ask about; the
 * inquiry decides.
 */
final class EasypaisaAdapter implements ProviderAdapter
{
    private const HOSTS = [
        ProviderCredentials::SANDBOX => 'https://easypaystg.easypaisa.com.pk',
        ProviderCredentials::PRODUCTION => 'https://easypay.easypaisa.com.pk',
    ];

    private const CHARGE_PATH = '/easypay-service/rest/v4/initiate-ma-transaction';

    private const INQUIRY_PATH = '/easypay-service/rest/v4/inquire-transaction';

    private const OK = '0000';

    public function key(): string
    {
        return 'easypaisa';
    }

    public function label(): string
    {
        return 'Easypaisa';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            walletPayment: true, cardPayment: false, statusInquiry: true,
            refund: false, partialRefund: false, // refunds are made in the Easypaisa merchant portal and recorded here
            notifications: true, signedNotifications: false,
            currencies: ['PKR'], verified: false,
            note: 'Mobile account payments. Easypaisa notifications are not signed; the platform asks Easypaisa before it believes one. Not yet checked in the Easypaisa sandbox.',
        );
    }

    public function credentialFields(): array
    {
        return [
            new CredentialField('store_id', 'Store ID', secret: false, maxLength: 20, pattern: '/^[0-9]+$/'),
            new CredentialField('username', 'API username', secret: false, maxLength: 100),
            new CredentialField('password', 'API password', secret: true, maxLength: 100),
            new CredentialField('account_number', 'Merchant account number', secret: false, maxLength: 30, pattern: '/^[0-9]+$/', help: 'Used when asking Easypaisa about a payment.'),
        ];
    }

    public function payerFields(): array
    {
        return [new PayerField(PayerField::MOBILE, 'Easypaisa mobile number', '03XX XXXXXXX')];
    }

    public function chargeWallet(WalletCharge $charge, ProviderCredentials $credentials): ProviderResult
    {
        $answer = ProviderHttp::postJson(self::HOSTS[$credentials->environment].self::CHARGE_PATH, [
            'orderId' => $charge->reference,
            'storeId' => $credentials->get('store_id'),
            'transactionAmount' => $charge->amountDecimal(),
            'transactionType' => 'MA',
            'mobileAccountNo' => (string) $charge->mobile,
            'emailAddress' => '',
        ], $this->headers($credentials), timeoutSeconds: 90);

        if ($answer instanceof ProviderResult) {
            return $answer;
        }
        $code = (string) ($answer['responseCode'] ?? '');
        $message = isset($answer['responseDesc']) ? mb_substr((string) $answer['responseDesc'], 0, 255) : null;
        $reference = isset($answer['transactionId']) && $answer['transactionId'] !== '' ? (string) $answer['transactionId'] : null;

        return match (true) {
            $code === '' => new ProviderResult(ProviderOutcome::Unknown, FailureCategory::UnknownProviderError, null, $message),
            $code === self::OK => new ProviderResult(ProviderOutcome::Succeeded, null, $code, $message, $reference),
            default => new ProviderResult(ProviderOutcome::Failed, FailureCategory::PaymentDeclined, $code, $message, $reference),
        };
    }

    public function inquire(WalletCharge $charge, ProviderCredentials $credentials): ProviderResult
    {
        $answer = ProviderHttp::postJson(self::HOSTS[$credentials->environment].self::INQUIRY_PATH, [
            'orderId' => $charge->reference,
            'storeId' => $credentials->get('store_id'),
            'accountNum' => $credentials->get('account_number'),
        ], $this->headers($credentials));

        if ($answer instanceof ProviderResult) {
            return $answer;
        }
        if (($answer['responseCode'] ?? null) !== self::OK) {
            // The inquiry itself did not work: we still do not know.
            return new ProviderResult(ProviderOutcome::Unknown, FailureCategory::UnknownProviderError, isset($answer['responseCode']) ? (string) $answer['responseCode'] : null, isset($answer['responseDesc']) ? mb_substr((string) $answer['responseDesc'], 0, 255) : null);
        }

        $status = strtoupper((string) ($answer['transactionStatus'] ?? ''));
        $reference = isset($answer['transactionId']) && $answer['transactionId'] !== '' ? (string) $answer['transactionId'] : null;
        $amount = isset($answer['transactionAmount']) && is_numeric($answer['transactionAmount']) ? (int) round(((float) $answer['transactionAmount']) * 100) : null;

        return match ($status) {
            'PAID' => new ProviderResult(ProviderOutcome::Succeeded, null, $status, null, $reference, $amount, 'PKR'),
            'PENDING', 'INITIATED' => new ProviderResult(ProviderOutcome::Pending, null, $status, null, $reference),
            'FAILED', 'DROPPED', 'EXPIRED', 'REVERSED', 'REJECTED' => new ProviderResult(ProviderOutcome::Failed, $status === 'EXPIRED' ? FailureCategory::PaymentExpired : FailureCategory::PaymentDeclined, $status, null, $reference),
            default => new ProviderResult(ProviderOutcome::Unknown, FailureCategory::UnknownProviderError, $status !== '' ? mb_substr($status, 0, 32) : null),
        };
    }

    public function refund(WalletCharge $charge, int $amountMinor, string $refundReference, ProviderCredentials $credentials): ProviderResult
    {
        throw new \LogicException('Easypaisa refunds are not supported by this adapter.');
    }

    public function notificationReference(array $input, array $headers): ?string
    {
        foreach (['orderRefNumber', 'orderRefNum', 'orderId'] as $key) {
            if (isset($input[$key]) && is_string($input[$key]) && $input[$key] !== '') {
                return $input[$key];
            }
        }

        return null; // a notification that only carries a URL is not followed (no outbound call to an address from a request)
    }

    public function verifyNotification(string $rawBody, array $input, array $headers, ProviderCredentials $credentials): bool
    {
        return true; // not signed (see the class note): the engine trusts only the inquiry
    }

    public function notificationSentAt(array $input, array $headers): ?int
    {
        return null;
    }

    /** @return array<string, string> */
    private function headers(ProviderCredentials $credentials): array
    {
        return ['Credentials' => base64_encode($credentials->get('username').':'.$credentials->get('password'))];
    }
}
