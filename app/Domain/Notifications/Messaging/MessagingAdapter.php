<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Messaging;

use App\Domain\Payments\Providers\CredentialField;
use App\Domain\Payments\Providers\ProviderCredentials;
use Illuminate\Http\Request;

/**
 * Phase B50 (Module 21 §31 "Provider Abstraction"; owner decision 3: the
 * notification domain stays provider-agnostic): the contract every SMS and
 * WhatsApp provider implements. MessagingChannel (the notification channel)
 * and MessagingWebhookService talk only to this interface.
 *
 * Stateless: one message in, one provider request out. Never writes to the
 * database; never logs credentials, numbers or message text.
 */
interface MessagingAdapter
{
    public function key(): string;

    public function label(): string;

    public function capabilities(): MessagingCapabilities;

    /** @return list<CredentialField> */
    public function credentialFields(): array;

    public function send(OutgoingMessage $message, ProviderCredentials $credentials): SendOutcome;

    /**
     * Whether the provider has approved this template (when templateCheck):
     * approved | rejected | paused | submitted | unknown.
     */
    public function templateStatus(string $name, string $language, ProviderCredentials $credentials): string;

    /** Whether this webhook request is signed by the provider with this account's secret. */
    public function verifyWebhook(Request $request, ProviderCredentials $credentials, string $publicUrl): bool;

    /** @return list<ProviderEvent> what the (verified) webhook reports */
    public function parseWebhook(Request $request): array;
}
