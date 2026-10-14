<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Messaging;

/**
 * Module 21 §31: what a messaging provider can do, as built. `templates`:
 * business-initiated messages need approved templates (WhatsApp);
 * `freeText`: plain text can be sent at any time (SMS). `verifiedApi`: the
 * request format was checked against the provider's public documentation;
 * `needsPlatformGoAhead`: real (production) use waits for Umar Techy to open
 * it (owner decision 3: an SMS provider is chosen after sender-ID, delivery
 * and pricing checks).
 */
final class MessagingCapabilities
{
    public function __construct(
        public readonly string $channel,
        public readonly bool $templates,
        public readonly bool $freeText,
        public readonly bool $deliveryReports,
        public readonly bool $readReceipts,
        public readonly bool $inbound,
        public readonly bool $templateCheck,
        public readonly bool $verifiedApi,
        public readonly bool $needsPlatformGoAhead,
        public readonly ?string $note = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'channel' => $this->channel, 'templates' => $this->templates, 'free_text' => $this->freeText,
            'delivery_reports' => $this->deliveryReports, 'read_receipts' => $this->readReceipts, 'inbound' => $this->inbound,
            'template_check' => $this->templateCheck, 'verified_api' => $this->verifiedApi,
            'needs_platform_go_ahead' => $this->needsPlatformGoAhead, 'note' => $this->note,
        ];
    }
}
