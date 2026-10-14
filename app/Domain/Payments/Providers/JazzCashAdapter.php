<?php

declare(strict_types=1);

namespace App\Domain\Payments\Providers;

/**
 * JazzCash mobile-account payments (owner decision 2, Module 12 §20).
 *
 * Integration used: the server-to-server Mobile Account REST API — the
 * shopper gives the wallet number and the last six digits of their CNIC,
 * JazzCash asks them to approve on their phone, the answer comes back to
 * the server. JazzCash's browser "page redirection" form was NOT used: it
 * sends the merchant password through the shopper's browser, which Module
 * 12 §20 and §33 forbid.
 *
 * NOT YET VERIFIED. JazzCash publishes its API guide to merchants only; the
 * request fields, the response codes and the hash below follow that guide
 * as it is commonly published and must be confirmed in the JazzCash sandbox
 * with real sandbox credentials before any store can take real money
 * (`payments.live_providers`, Super Admin). Everything provider-specific is
 * in this one class.
 *
 * Security hash: HMAC-SHA256 keyed with the Integrity Salt, over the salt
 * and the non-empty pp_ / ppmpf_ values in key order joined with "&",
 * upper-case hex. Answers that carry a hash are checked the same way.
 */
final class JazzCashAdapter implements ProviderAdapter
{
    private const HOSTS = [
        ProviderCredentials::SANDBOX => 'https://sandbox.jazzcash.com.pk',
        ProviderCredentials::PRODUCTION => 'https://payments.jazzcash.com.pk',
    ];

    private const CHARGE_PATH = '/ApplicationAPI/API/2.0/Purchase/DoMWalletTransaction';

    private const INQUIRY_PATH = '/ApplicationAPI/API/PaymentInquiry/Inquire';

    /** Response codes, by meaning. Any other code is a failure; an inquiry without a code is unknown. */
    private const SUCCESS = ['000'];

    private const PENDING = ['124', '157'];

    public function key(): string
    {
        return 'jazzcash';
    }

    public function label(): string
    {
        return 'JazzCash';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            walletPayment: true, cardPayment: false, statusInquiry: true,
            refund: false, partialRefund: false, // refunds are made in the JazzCash merchant portal and recorded here
            notifications: true, signedNotifications: true,
            currencies: ['PKR'], verified: false,
            note: 'Mobile account payments. Cards through JazzCash need its hosted page, which is not built. Not yet checked in the JazzCash sandbox.',
        );
    }

    public function credentialFields(): array
    {
        return [
            new CredentialField('merchant_id', 'Merchant ID', secret: false, maxLength: 40, pattern: '/^[A-Za-z0-9]+$/'),
            new CredentialField('password', 'Password', secret: true, maxLength: 100),
            new CredentialField('integrity_salt', 'Integrity salt', secret: true, maxLength: 100, help: 'Used to sign every request and to check JazzCash\'s answers.'),
        ];
    }

    public function payerFields(): array
    {
        return [
            new PayerField(PayerField::MOBILE, 'JazzCash mobile number', '03XX XXXXXXX'),
            new PayerField(PayerField::CNIC_LAST6, 'Last 6 digits of CNIC', 'The CNIC the JazzCash account is registered with'),
        ];
    }

    public function chargeWallet(WalletCharge $charge, ProviderCredentials $credentials): ProviderResult
    {
        $fields = [
            'pp_Language' => 'EN',
            'pp_MerchantID' => $credentials->get('merchant_id'),
            'pp_SubMerchantID' => '',
            'pp_Password' => $credentials->get('password'),
            'pp_BankID' => '',
            'pp_ProductID' => '',
            'pp_TxnRefNo' => $charge->reference,
            'pp_Amount' => (string) $charge->amountMinor, // paisa
            'pp_TxnCurrency' => $charge->currency,
            'pp_TxnDateTime' => $this->time($charge->createdAt),
            'pp_BillReference' => $charge->reference,
            'pp_Description' => mb_substr($charge->description, 0, 100),
            'pp_TxnExpiryDateTime' => $this->time($charge->expiresAt),
            'pp_MobileNumber' => (string) $charge->mobile,
            'pp_CNIC' => (string) $charge->cnicLast6,
        ];
        $fields['pp_SecureHash'] = self::hash($fields, $credentials->get('integrity_salt'));

        // The shopper approves on the phone while the request waits.
        $answer = ProviderHttp::postJson(self::HOSTS[$credentials->environment].self::CHARGE_PATH, $fields, timeoutSeconds: 90);

        return $this->read($answer, $credentials, inquiry: false);
    }

    public function inquire(WalletCharge $charge, ProviderCredentials $credentials): ProviderResult
    {
        $fields = [
            'pp_TxnRefNo' => $charge->reference,
            'pp_MerchantID' => $credentials->get('merchant_id'),
            'pp_Password' => $credentials->get('password'),
        ];
        $fields['pp_SecureHash'] = self::hash($fields, $credentials->get('integrity_salt'));

        return $this->read(ProviderHttp::postJson(self::HOSTS[$credentials->environment].self::INQUIRY_PATH, $fields), $credentials, inquiry: true);
    }

    public function refund(WalletCharge $charge, int $amountMinor, string $refundReference, ProviderCredentials $credentials): ProviderResult
    {
        throw new \LogicException('JazzCash refunds are not supported by this adapter.');
    }

    public function notificationReference(array $input, array $headers): ?string
    {
        $reference = $input['pp_TxnRefNo'] ?? null;

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    public function verifyNotification(string $rawBody, array $input, array $headers, ProviderCredentials $credentials): bool
    {
        $given = $input['pp_SecureHash'] ?? null;
        if (! is_string($given) || $given === '') {
            return false;
        }
        $fields = array_filter($input, fn ($value, $key) => $key !== 'pp_SecureHash' && is_scalar($value), ARRAY_FILTER_USE_BOTH);

        return hash_equals(self::hash($fields, $credentials->get('integrity_salt')), strtoupper($given));
    }

    public function notificationSentAt(array $input, array $headers): ?int
    {
        return null; // pp_TxnDateTime is when the payment began, not when the notice was sent
    }

    /** @param array<string, mixed> $fields */
    public static function hash(array $fields, string $salt): string
    {
        $signed = array_filter($fields, fn ($value, $key) => (str_starts_with((string) $key, 'pp_') || str_starts_with((string) $key, 'ppmpf_')) && $key !== 'pp_SecureHash' && (string) $value !== '', ARRAY_FILTER_USE_BOTH);
        ksort($signed, SORT_STRING);

        return strtoupper(hash_hmac('sha256', $salt.'&'.implode('&', array_map('strval', $signed)), $salt));
    }

    /** @param array<string, mixed>|ProviderResult $answer */
    private function read(array|ProviderResult $answer, ProviderCredentials $credentials, bool $inquiry): ProviderResult
    {
        if ($answer instanceof ProviderResult) {
            return $answer;
        }
        if (isset($answer['pp_SecureHash']) && ! $this->verifyNotification('', $answer, [], $credentials)) {
            // An answer we cannot trust is no answer (Module 12 §30).
            return ProviderResult::unknown(FailureCategory::SignatureInvalid, 'The JazzCash answer failed the hash check.');
        }

        // An inquiry reports on the inquiry first and on the payment in pp_PaymentResponseCode.
        $code = (string) ($inquiry ? ($answer['pp_PaymentResponseCode'] ?? '') : ($answer['pp_ResponseCode'] ?? ''));
        if ($inquiry && ($answer['pp_ResponseCode'] ?? null) !== '000') {
            $code = '';
        }
        $message = isset($answer['pp_ResponseMessage']) ? mb_substr((string) $answer['pp_ResponseMessage'], 0, 255) : null;
        $reference = isset($answer['pp_RetreivalReferenceNo']) && $answer['pp_RetreivalReferenceNo'] !== '' ? (string) $answer['pp_RetreivalReferenceNo'] : null;
        $amount = isset($answer['pp_Amount']) && is_numeric($answer['pp_Amount']) ? (int) $answer['pp_Amount'] : null;
        $currency = isset($answer['pp_TxnCurrency']) && is_string($answer['pp_TxnCurrency']) && $answer['pp_TxnCurrency'] !== '' ? $answer['pp_TxnCurrency'] : null;

        return match (true) {
            $code === '' => new ProviderResult(ProviderOutcome::Unknown, FailureCategory::UnknownProviderError, null, $message),
            in_array($code, self::SUCCESS, true) => new ProviderResult(ProviderOutcome::Succeeded, null, $code, $message, $reference, $amount, $currency),
            in_array($code, self::PENDING, true) => new ProviderResult(ProviderOutcome::Pending, null, $code, $message, $reference),
            default => new ProviderResult(ProviderOutcome::Failed, FailureCategory::PaymentDeclined, $code, $message, $reference),
        };
    }

    private function time(\DateTimeInterface $at): string
    {
        return \Carbon\CarbonImmutable::instance($at)->setTimezone('Asia/Karachi')->format('YmdHis');
    }
}
