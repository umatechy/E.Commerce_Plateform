<?php

declare(strict_types=1);

namespace App\Domain\Payments\Providers;

/**
 * Phase B48 (Module 12 §4, §85–86; SRS PAY-001): the contract every online
 * payment provider implements. The payment engine (OnlinePaymentService)
 * talks only to this interface, so a provider can be added without touching
 * checkout, orders or billing (Module 12 Final Rule #28).
 *
 * An adapter is stateless: it turns one platform request into one provider
 * request and the provider's answer into a ProviderResult. It never writes
 * to the database, never logs credentials, and never decides that a payment
 * is paid on its own — the engine records what the provider confirmed.
 *
 * The flow built here is the mobile-wallet charge (Module 12 §20–21): the
 * shopper gives the wallet number, the provider asks them on their phone to
 * approve, the server reads the answer. Everything stays server-to-server:
 * no merchant credential ever reaches a browser (Module 12 §20, §33).
 */
interface ProviderAdapter
{
    /** Stable code, stored on accounts and attempts: jazzcash, easypaisa, simulator. */
    public function key(): string;

    /** The provider's name as shoppers know it. */
    public function label(): string;

    public function capabilities(): ProviderCapabilities;

    /** @return list<CredentialField> what a merchant account needs (Module 12 §31) */
    public function credentialFields(): array;

    /** @return list<PayerField> what the shopper enters at checkout */
    public function payerFields(): array;

    /** Ask the shopper's wallet for the money. A timeout is Unknown, never Failed (Module 12 §61). */
    public function chargeWallet(WalletCharge $charge, ProviderCredentials $credentials): ProviderResult;

    /** Ask the provider what happened to a charge (Module 12 §25 step 6, §55; SRS PAY-005). */
    public function inquire(WalletCharge $charge, ProviderCredentials $credentials): ProviderResult;

    /** Only called when capabilities()->refund is true. */
    public function refund(WalletCharge $charge, int $amountMinor, string $refundReference, ProviderCredentials $credentials): ProviderResult;

    /**
     * Our own reference in an incoming notification, read without trusting
     * anything else in it. Null: not a notification we can place.
     *
     * @param array<string, mixed> $input
     * @param array<string, string|null> $headers lower-case names
     */
    public function notificationReference(array $input, array $headers): ?string;

    /**
     * Whether the notification is signed by the provider with this account's
     * secret. Providers that do not sign notifications return true only when
     * capabilities()->signedNotifications is false — the engine then trusts
     * nothing but the reference and asks the provider itself.
     *
     * @param array<string, mixed> $input
     * @param array<string, string|null> $headers
     */
    public function verifyNotification(string $rawBody, array $input, array $headers, ProviderCredentials $credentials): bool;

    /**
     * When the provider says it sent the notification (Unix seconds), if it
     * says so; used for the replay window (Module 12 §29; SRS PAY-008).
     *
     * @param array<string, mixed> $input
     * @param array<string, string|null> $headers
     */
    public function notificationSentAt(array $input, array $headers): ?int;
}
