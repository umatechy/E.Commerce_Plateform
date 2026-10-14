<?php

declare(strict_types=1);

namespace App\Domain\Payments\Providers;

/**
 * Module 12 §59 "Payment Error Model": provider errors in safe platform
 * categories. The customer message explains what to do without exposing
 * provider internals.
 */
enum FailureCategory: string
{
    case InvalidRequest = 'invalid_request';
    case AuthenticationFailed = 'authentication_failed';
    case ProviderUnavailable = 'provider_unavailable';
    case PaymentDeclined = 'payment_declined';
    case PaymentExpired = 'payment_expired';
    case AmountMismatch = 'amount_mismatch';
    case CurrencyMismatch = 'currency_mismatch';
    case SignatureInvalid = 'signature_invalid';
    case DuplicateRequest = 'duplicate_request';
    case UnknownProviderError = 'unknown_provider_error';

    public function customerMessage(): string
    {
        return match ($this) {
            self::PaymentDeclined => 'The payment was not approved. Check your balance and try again, or choose another way to pay.',
            self::PaymentExpired => 'The payment request ran out of time. You can try again.',
            self::ProviderUnavailable => 'The payment service is not answering right now. Please try again in a few minutes, or choose another way to pay.',
            self::InvalidRequest => 'The payment could not be started. Check the number you entered and try again.',
            self::AuthenticationFailed, self::SignatureInvalid, self::DuplicateRequest, self::UnknownProviderError => 'The payment could not be completed. Please try again or choose another way to pay.',
            self::AmountMismatch, self::CurrencyMismatch => 'We are checking this payment with the store. Please do not pay again.',
        };
    }

    /** What the store's staff see (Module 12 §68 "failure reason"). */
    public function staffLabel(): string
    {
        return match ($this) {
            self::InvalidRequest => 'Request not accepted',
            self::AuthenticationFailed => 'Merchant credentials refused',
            self::ProviderUnavailable => 'Provider not answering',
            self::PaymentDeclined => 'Declined or not approved',
            self::PaymentExpired => 'Ran out of time',
            self::AmountMismatch => 'Amount does not match',
            self::CurrencyMismatch => 'Currency does not match',
            self::SignatureInvalid => 'Signature check failed',
            self::DuplicateRequest => 'Duplicate request',
            self::UnknownProviderError => 'Unknown provider error',
        };
    }
}
